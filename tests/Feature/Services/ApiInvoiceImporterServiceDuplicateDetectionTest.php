<?php

namespace Tests\Feature\Services;

use App\Models\ApiInvoiceImport;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ApiInvoiceImporterService;
use App\Support\InvoiceFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Detección de facturas duplicadas EXACTAS (re-emisiones de Jaremar).
 *
 * Contexto (incidente 2026-07): Jaremar re-emite la misma factura económica
 * (mismo cliente, mismos productos y cantidades, mismo total) con número
 * fiscal NUEVO en manifiesto NUEVO, generalmente al día siguiente. La huella
 * canónica (InvoiceFingerprint) las detecta contra la BD en una ventana de
 * N días. Contrato:
 *
 *   - BLOQUE  (>= block_threshold matches en el manifiesto entrante):
 *     rechazo automático FACTURAS_DUPLICADAS_EXACTAS con detalle por factura.
 *   - AISLADA (< threshold): entra marcada (duplicate_of_invoice_id).
 *   - Pedido semanal legítimo (fuera de ventana): entra limpia.
 */
class ApiInvoiceImporterServiceDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oac;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        $this->supplier = Supplier::factory()->create(['is_active' => true]);
        $this->oac = Warehouse::factory()->oac()->create();
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function service(): ApiInvoiceImporterService
    {
        return $this->app->make(ApiInvoiceImporterService::class);
    }

    private function importRecord(): ApiInvoiceImport
    {
        return ApiInvoiceImport::create([
            'batch_uuid' => fake()->uuid(),
            'api_key_hint' => 'test***',
            'ip_address' => '127.0.0.1',
            'total_received' => 1,
            'raw_payload' => [],
            'payload_hash' => md5(fake()->uuid()),
            'status' => 'received',
        ]);
    }

    /**
     * Factura Jaremar con composición controlada: un cliente, un producto,
     * una cantidad y un total definen la huella. Reusar los mismos valores
     * con otro Nfactura simula una re-emisión de Jaremar.
     */
    private function pedido(
        string $nfactura,
        string $manifiesto,
        string $clienteId,
        string $producto,
        float $fracciones,
        float $total,
        ?string $fecha = null,
        array $lineOverrides = [],
    ): array {
        return [
            'Nfactura' => $nfactura,
            'NumeroManifiesto' => $manifiesto,
            'Total' => $total,
            'FechaFactura' => $fecha ?? now()->toIso8601String(),
            'Almacen' => 'OAC',
            'Vendedorid' => 'V01',
            'Vendedor' => 'VENDEDOR PRUEBA',
            'Clienteid' => $clienteId,
            'Cliente' => 'PULPERIA '.$clienteId,
            'Rtn' => '',
            'TipoPago' => 'CONTADO',
            'DiasCred' => 0,
            'TipoFactura' => 'FAC',
            'EstadoFactura' => 1,
            'NumeroFacturaLX' => 'LX'.fake()->unique()->numerify('######'),
            'NumeroPedido' => 'PED'.fake()->unique()->numerify('######'),
            'NumeroRuta' => '001',
            'Direccion' => 'COL. TEST',
            'EntregarA' => 'PULPERIA '.$clienteId,
            'LineasFactura' => [array_merge([
                'ProductoId' => $producto,
                'ProductoDesc' => 'PRODUCTO '.$producto,
                'NumeroLinea' => 1,
                'Total' => $total,
                'Precio' => 15.0,
                'Subtotal' => $total,
                'Costo' => 0.0,
                'CantidadFracciones' => $fracciones,
                'CantidadDecimal' => $fracciones,
                'CantidadCaja' => 0.0,
                'FactorConversion' => 1,
                'UniVenta' => 'UN',
                'TipoProducto' => 'A',
                'Descuento' => 0.0,
                'Impuesto' => 0.0,
                'Impuesto18' => 0.0,
                'PorcentajeDescuento' => 0.0,
                'PorcentajeImpuesto' => 0.0,
                'CantidadUnidadMinVenta' => $fracciones,
                'PrecioUnidadMinVenta' => 15.0,
                'Peso' => 0.0,
                'Volumen' => 0.0,
                'Id' => fake()->unique()->numberBetween(1, 999999),
                'InvoiceId' => fake()->numberBetween(1, 999999),
            ], $lineOverrides)],
        ];
    }

    /** Importa el "día 1" y devuelve el summary. */
    private function importarOriginales(array $facturas): array
    {
        return $this->service()->processBatch($facturas, $this->importRecord());
    }

    // ═══════════════════════════════════════════════════════════════
    //  1. HUELLA CANÓNICA
    // ═══════════════════════════════════════════════════════════════

    public function test_fingerprint_ignora_numero_fecha_y_redondeo_por_linea(): void
    {
        // Caso real: Jaremar re-emite con número y fecha nuevos y recalcula
        // centavos por línea (2,764.79 vs 2,764.80) — la huella NO cambia.
        $original = $this->pedido('F-A', 'MAN-1', 'C-100', 'ART-1', 24.0, 4607.99, now()->subDay()->toIso8601String());
        $reemision = $this->pedido('F-B', 'MAN-2', 'C-100', 'ART-1', 24.0, 4607.99, now()->toIso8601String(), [
            'Total' => 4607.98,     // redondeo distinto POR LÍNEA
            'Impuesto' => 0.01,
        ]);

        $this->assertNotNull(InvoiceFingerprint::fromPayload($original));
        $this->assertSame(
            InvoiceFingerprint::fromPayload($original),
            InvoiceFingerprint::fromPayload($reemision),
        );
    }

    public function test_fingerprint_cambia_con_cliente_cantidad_o_total(): void
    {
        $base = $this->pedido('F-A', 'MAN-1', 'C-100', 'ART-1', 24.0, 450.0);

        $otroCliente = $this->pedido('F-B', 'MAN-1', 'C-200', 'ART-1', 24.0, 450.0);
        $otraCantidad = $this->pedido('F-C', 'MAN-1', 'C-100', 'ART-1', 12.0, 450.0);
        $otroTotal = $this->pedido('F-D', 'MAN-1', 'C-100', 'ART-1', 24.0, 900.0);

        $fp = InvoiceFingerprint::fromPayload($base);
        $this->assertNotSame($fp, InvoiceFingerprint::fromPayload($otroCliente));
        $this->assertNotSame($fp, InvoiceFingerprint::fromPayload($otraCantidad));
        $this->assertNotSame($fp, InvoiceFingerprint::fromPayload($otroTotal));
    }

    public function test_fingerprint_de_payload_coincide_con_fingerprint_de_bd(): void
    {
        $payload = $this->pedido('F-DB-1', 'MAN-DB', 'C-100', 'ART-1', 24.0, 450.0);
        $this->importarOriginales([$payload]);

        $invoice = Invoice::where('invoice_number', 'F-DB-1')->firstOrFail();

        // Guardada en el insert y reproducible desde la BD (contrato del
        // backfill: ambos caminos producen el mismo hash).
        $this->assertNotNull($invoice->fingerprint);
        $this->assertSame(InvoiceFingerprint::fromPayload($payload), $invoice->fingerprint);
        $this->assertSame(InvoiceFingerprint::fromInvoice($invoice), $invoice->fingerprint);
    }

    // ═══════════════════════════════════════════════════════════════
    //  2. RE-EMISIÓN EN BLOQUE → RECHAZO
    // ═══════════════════════════════════════════════════════════════

    public function test_reemision_en_bloque_rechaza_manifiesto_completo(): void
    {
        $ayer = now()->subDay()->toIso8601String();

        $this->importarOriginales([
            $this->pedido('F-ORIG-1', 'MAN-ORIG', 'C-100', 'ART-100', 10.0, 100.0, $ayer),
            $this->pedido('F-ORIG-2', 'MAN-ORIG', 'C-200', 'ART-200', 20.0, 200.0, $ayer),
            $this->pedido('F-ORIG-3', 'MAN-ORIG', 'C-300', 'ART-300', 30.0, 300.0, $ayer),
        ]);

        // Al día siguiente Jaremar re-emite las 3 con números nuevos en un
        // manifiesto nuevo (patrón real 790273/790277 → 790601).
        $summary = $this->service()->processBatch([
            $this->pedido('F-REEM-1', 'MAN-REEM', 'C-100', 'ART-100', 10.0, 100.0),
            $this->pedido('F-REEM-2', 'MAN-REEM', 'C-200', 'ART-200', 20.0, 200.0),
            $this->pedido('F-REEM-3', 'MAN-REEM', 'C-300', 'ART-300', 30.0, 300.0),
        ], $this->importRecord());

        $this->assertSame(0, $summary['invoices_inserted']);
        $this->assertSame(3, $summary['invoices_rejected']);
        $this->assertCount(1, $summary['manifiestos_rechazados']);

        $rechazo = $summary['manifiestos_rechazados'][0];
        $this->assertSame('MAN-REEM', $rechazo['manifiesto']);
        $this->assertSame('FACTURAS_DUPLICADAS_EXACTAS', $rechazo['motivo']);
        $this->assertCount(3, $rechazo['facturas_duplicadas_exactas']);

        // Detalle por factura: entrante ≈ original, con su manifiesto.
        $detalle = collect($rechazo['facturas_duplicadas_exactas'])->keyBy('factura');
        $this->assertSame('F-ORIG-1', $detalle['F-REEM-1']['identica_a']);
        $this->assertSame('MAN-ORIG', $detalle['F-REEM-1']['manifiesto_original']);
        $this->assertSame('F-ORIG-2', $detalle['F-REEM-2']['identica_a']);
        $this->assertSame('F-ORIG-3', $detalle['F-REEM-3']['identica_a']);

        // Nada del manifiesto re-emitido entró.
        $this->assertSame(0, Invoice::where('invoice_number', 'like', 'F-REEM-%')->count());
        $this->assertSame(0, Manifest::where('number', 'MAN-REEM')->count());
    }

    public function test_reemision_en_bloque_detecta_originales_de_varios_manifiestos(): void
    {
        // Caso real 790601: un solo manifiesto nuevo mezcla copias del
        // 790273 Y del 790277. El umbral cuenta matches del manifiesto
        // ENTRANTE sin importar de qué manifiesto vengan las originales.
        $ayer = now()->subDay()->toIso8601String();

        $this->importarOriginales([
            $this->pedido('F-ORIG-A1', 'MAN-ORIG-A', 'C-100', 'ART-100', 10.0, 100.0, $ayer),
            $this->pedido('F-ORIG-A2', 'MAN-ORIG-A', 'C-200', 'ART-200', 20.0, 200.0, $ayer),
            $this->pedido('F-ORIG-B1', 'MAN-ORIG-B', 'C-300', 'ART-300', 30.0, 300.0, $ayer),
        ]);

        $summary = $this->service()->processBatch([
            $this->pedido('F-MIX-1', 'MAN-MIX', 'C-100', 'ART-100', 10.0, 100.0),
            $this->pedido('F-MIX-2', 'MAN-MIX', 'C-200', 'ART-200', 20.0, 200.0),
            $this->pedido('F-MIX-3', 'MAN-MIX', 'C-300', 'ART-300', 30.0, 300.0),
        ], $this->importRecord());

        $this->assertSame(0, $summary['invoices_inserted']);
        $this->assertSame('FACTURAS_DUPLICADAS_EXACTAS', $summary['manifiestos_rechazados'][0]['motivo']);

        $origenes = collect($summary['manifiestos_rechazados'][0]['facturas_duplicadas_exactas'])
            ->pluck('manifiesto_original')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['MAN-ORIG-A', 'MAN-ORIG-B'], $origenes);
    }

    // ═══════════════════════════════════════════════════════════════
    //  3. MATCH AISLADO → ENTRA MARCADA
    // ═══════════════════════════════════════════════════════════════

    public function test_match_aislado_entra_marcado_como_posible_duplicada(): void
    {
        $ayer = now()->subDay()->toIso8601String();

        $this->importarOriginales([
            $this->pedido('F-ORIG-1', 'MAN-ORIG', 'C-100', 'ART-100', 10.0, 100.0, $ayer),
        ]);
        $original = Invoice::where('invoice_number', 'F-ORIG-1')->firstOrFail();

        // 1 idéntica + 2 limpias en el manifiesto entrante → bajo el umbral
        // de bloque (3): todo entra, la idéntica queda marcada.
        $summary = $this->service()->processBatch([
            $this->pedido('F-AIS-1', 'MAN-AIS', 'C-100', 'ART-100', 10.0, 100.0),
            $this->pedido('F-AIS-2', 'MAN-AIS', 'C-400', 'ART-400', 40.0, 400.0),
            $this->pedido('F-AIS-3', 'MAN-AIS', 'C-500', 'ART-500', 50.0, 500.0),
        ], $this->importRecord());

        $this->assertSame(3, $summary['invoices_inserted']);
        $this->assertSame(0, $summary['invoices_rejected']);

        $marcada = Invoice::where('invoice_number', 'F-AIS-1')->firstOrFail();
        $this->assertSame($original->id, $marcada->duplicate_of_invoice_id);

        // Las limpias no se tocan.
        $this->assertNull(Invoice::where('invoice_number', 'F-AIS-2')->value('duplicate_of_invoice_id'));
        $this->assertNull(Invoice::where('invoice_number', 'F-AIS-3')->value('duplicate_of_invoice_id'));

        // La advertencia viaja en el summary (→ respuesta a Jaremar).
        $this->assertNotEmpty($summary['warnings']);
        $this->assertStringContainsString('F-AIS-1', implode(' ', $summary['warnings']));
        $this->assertStringContainsString('F-ORIG-1', implode(' ', $summary['warnings']));
    }

    public function test_dos_matches_quedan_bajo_el_umbral_y_entran_marcados(): void
    {
        $ayer = now()->subDay()->toIso8601String();

        $this->importarOriginales([
            $this->pedido('F-ORIG-1', 'MAN-ORIG', 'C-100', 'ART-100', 10.0, 100.0, $ayer),
            $this->pedido('F-ORIG-2', 'MAN-ORIG', 'C-200', 'ART-200', 20.0, 200.0, $ayer),
        ]);

        $summary = $this->service()->processBatch([
            $this->pedido('F-DOS-1', 'MAN-DOS', 'C-100', 'ART-100', 10.0, 100.0),
            $this->pedido('F-DOS-2', 'MAN-DOS', 'C-200', 'ART-200', 20.0, 200.0),
            $this->pedido('F-DOS-3', 'MAN-DOS', 'C-600', 'ART-600', 60.0, 600.0),
        ], $this->importRecord());

        // 2 matches < block_threshold (3) → entran las 3, dos marcadas.
        $this->assertSame(3, $summary['invoices_inserted']);
        $this->assertNotNull(Invoice::where('invoice_number', 'F-DOS-1')->value('duplicate_of_invoice_id'));
        $this->assertNotNull(Invoice::where('invoice_number', 'F-DOS-2')->value('duplicate_of_invoice_id'));
        $this->assertNull(Invoice::where('invoice_number', 'F-DOS-3')->value('duplicate_of_invoice_id'));
    }

    // ═══════════════════════════════════════════════════════════════
    //  4. PEDIDO SEMANAL LEGÍTIMO → ENTRA LIMPIO
    // ═══════════════════════════════════════════════════════════════

    public function test_pedido_semanal_identico_fuera_de_ventana_entra_limpio(): void
    {
        // Caso real: PUL FERNANDA pide exactamente la misma canasta cada 7
        // días. Está fuera de la ventana (3 días) → ni rechazo ni marca.
        $haceUnaSemana = now()->subDays(7)->toIso8601String();

        $this->importarOriginales([
            $this->pedido('F-SEM-1', 'MAN-SEM-1', 'C-100', 'ART-100', 10.0, 191.16, $haceUnaSemana),
        ]);

        $summary = $this->service()->processBatch([
            $this->pedido('F-SEM-2', 'MAN-SEM-2', 'C-100', 'ART-100', 10.0, 191.16),
        ], $this->importRecord());

        $this->assertSame(1, $summary['invoices_inserted']);
        $this->assertSame(0, $summary['invoices_rejected']);
        $this->assertNull(Invoice::where('invoice_number', 'F-SEM-2')->value('duplicate_of_invoice_id'));
        $this->assertEmpty($summary['warnings']);
    }

    public function test_deteccion_apagada_por_config_deja_pasar_todo(): void
    {
        config(['invoices.duplicates.detection_enabled' => false]);

        $ayer = now()->subDay()->toIso8601String();

        $this->importarOriginales([
            $this->pedido('F-OFF-1', 'MAN-OFF-A', 'C-100', 'ART-100', 10.0, 100.0, $ayer),
            $this->pedido('F-OFF-2', 'MAN-OFF-A', 'C-200', 'ART-200', 20.0, 200.0, $ayer),
            $this->pedido('F-OFF-3', 'MAN-OFF-A', 'C-300', 'ART-300', 30.0, 300.0, $ayer),
        ]);

        // Con la detección apagada, el bloque re-emitido entra (comportamiento
        // previo al incidente). Red de seguridad ante falsos positivos.
        $summary = $this->service()->processBatch([
            $this->pedido('F-OFF-4', 'MAN-OFF-B', 'C-100', 'ART-100', 10.0, 100.0),
            $this->pedido('F-OFF-5', 'MAN-OFF-B', 'C-200', 'ART-200', 20.0, 200.0),
            $this->pedido('F-OFF-6', 'MAN-OFF-B', 'C-300', 'ART-300', 30.0, 300.0),
        ], $this->importRecord());

        $this->assertSame(3, $summary['invoices_inserted']);
        $this->assertSame(0, $summary['invoices_rejected']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  5. BACKFILL
    // ═══════════════════════════════════════════════════════════════

    public function test_backfill_recalcula_fingerprints_nulos_y_es_idempotente(): void
    {
        $this->importarOriginales([
            $this->pedido('F-BF-1', 'MAN-BF', 'C-100', 'ART-100', 10.0, 100.0),
            $this->pedido('F-BF-2', 'MAN-BF', 'C-200', 'ART-200', 20.0, 200.0),
        ]);

        $esperados = Invoice::whereIn('invoice_number', ['F-BF-1', 'F-BF-2'])
            ->pluck('fingerprint', 'invoice_number');
        $this->assertNotNull($esperados['F-BF-1']);

        // Simular histórico pre-migración: huellas en NULL.
        DB::table('invoices')->update(['fingerprint' => null]);

        $this->artisan('invoices:backfill-fingerprints')->assertSuccessful();

        $recalculados = Invoice::whereIn('invoice_number', ['F-BF-1', 'F-BF-2'])
            ->pluck('fingerprint', 'invoice_number');
        $this->assertSame($esperados['F-BF-1'], $recalculados['F-BF-1']);
        $this->assertSame($esperados['F-BF-2'], $recalculados['F-BF-2']);

        // Idempotente: segunda corrida no encuentra nada pendiente.
        $this->artisan('invoices:backfill-fingerprints')
            ->expectsOutputToContain('Facturas sin fingerprint: 0')
            ->assertSuccessful();
    }

    public function test_backfill_omite_facturas_sin_lineas(): void
    {
        // Factory sin líneas: no hay base para huella → queda NULL y fuera
        // de la detección (nunca un match espurio).
        $manifest = Manifest::factory()->create(['supplier_id' => $this->supplier->id]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oac->id,
            'invoice_number' => 'F-SINLINEAS',
        ]);

        $this->artisan('invoices:backfill-fingerprints')->assertSuccessful();

        $this->assertNull(Invoice::where('invoice_number', 'F-SINLINEAS')->value('fingerprint'));
    }
}
