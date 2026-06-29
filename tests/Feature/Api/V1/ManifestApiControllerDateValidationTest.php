<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiInvoiceImport;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests end-to-end para las validaciones de fechas V1/V2/V3 que
 * agregamos al pipeline de POST /api/v1/facturas/insertar.
 *
 * Cubre el paso 3 NUEVO del controller (ManifestDateValidator) que corre
 * ANTES del paso 4 (validación de manifests existentes viejos).
 *
 * Reglas validadas end-to-end:
 *   V1 — FECHAS_MEZCLADAS:                  rechazo 422 si un mismo
 *                                           NumeroManifiesto trae fechas
 *                                           distintas.
 *   V2 — FECHA_FACTURA_FUTURA:              rechazo 422 si FechaFactura > hoy.
 *   V3 — FECHA_FACTURA_DEMASIADO_ANTIGUA:   rechazo 422 si FechaFactura
 *                                           supera config.max_backdate_days.
 *   V4 — manifests.date derivada:           el manifest creado tiene
 *                                           date = FechaFactura, no now().
 *
 * Notificación: en cada fallo los admins reciben Filament Notification
 * en BD (sendToDatabase). Validamos que se haya creado.
 *
 * Carbon::setTestNow() fija "hoy" para que cálculos de antigüedad sean
 * deterministas — sin esto, un test corriendo al filo de medianoche
 * podría dar resultados distintos.
 */
class ManifestApiControllerDateValidationTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-hozana-dateval-1234';

    protected function setUp(): void
    {
        parent::setUp();

        // Fecha fija: 20 de mayo de 2026, 14:00 Honduras.
        // Cualquier comparación que olvide TZ caerá al día siguiente UTC.
        Carbon::setTestNow(
            Carbon::create(2026, 5, 20, 14, 0, 0, 'America/Tegucigalpa')
        );

        config([
            'api.jaremar_api_key' => self::API_KEY,
            'api.rate_limit_insertar_per_minute' => 100,
            'api.rate_limit_per_minute' => 100,
            // Defaults de PRODUCCIÓN: mezcla permitida + fecha = día de carga.
            'manifests.dates.timezone' => 'America/Tegucigalpa',
            'manifests.dates.allow_future' => false,
            'manifests.dates.max_backdate_days' => 30,
            'manifests.dates.reject_mixed_dates' => false,
            'manifests.dates.manifest_date_source' => 'upload',
        ]);

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']);

        Supplier::factory()->create(['is_active' => true]);

        Warehouse::factory()->oac()->create();
        Warehouse::factory()->oas()->create();
        Warehouse::factory()->oao()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'Nfactura' => 'F'.fake()->unique()->numerify('########'),
            'NumeroManifiesto' => 'MANDATEV001',
            'Total' => 450.0,
            'FechaFactura' => '2026-05-20T00:00:00.000Z', // hoy en TZ Honduras
            'Almacen' => 'OAC',
            'Vendedorid' => 'V01',
            'Vendedor' => 'VENDEDOR PRUEBA',
            'Clienteid' => 'C001',
            'Cliente' => 'PULPERIA PRUEBA',
            'Rtn' => '',
            'TipoPago' => 'CONTADO',
            'DiasCred' => 0,
            'TipoFactura' => 'FAC',
            'EstadoFactura' => 1,
            'NumeroFacturaLX' => 'LX'.fake()->unique()->numerify('######'),
            'NumeroPedido' => 'PED'.fake()->unique()->numerify('######'),
            'NumeroRuta' => '001',
            'Direccion' => 'COL. TEST, TEGUCIGALPA',
            'EntregarA' => 'PULPERIA PRUEBA',
            'LineasFactura' => [[
                'ProductoId' => 'ART-TEST-001',
                'ProductoDesc' => 'PRODUCTO DE PRUEBA',
                'NumeroLinea' => 1,
                'Total' => 450.0,
                'Precio' => 15.0,
                'Subtotal' => 450.0,
                'Costo' => 0.0,
                'CantidadFracciones' => 30.0,
                'CantidadDecimal' => 30.0,
                'CantidadCaja' => 0.0,
                'FactorConversion' => 1,
                'UniVenta' => 'UN',
                'TipoProducto' => 'A',
                'Descuento' => 0.0,
                'Impuesto' => 0.0,
                'Impuesto18' => 0.0,
                'PorcentajeDescuento' => 0.0,
                'PorcentajeImpuesto' => 0.0,
                'CantidadUnidadMinVenta' => 30.0,
                'PrecioUnidadMinVenta' => 15.0,
                'Peso' => 0.0,
                'Volumen' => 0.0,
                'Id' => fake()->unique()->numberBetween(1, 999999),
                'InvoiceId' => fake()->numberBetween(1, 999999),
            ]],
        ], $overrides);
    }

    private function postInsertar(array $payload)
    {
        return $this->postJson(
            '/api/v1/facturas/insertar',
            $payload,
            ['ApiKey' => self::API_KEY]
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V1 — FECHAS_MEZCLADAS
    // ═══════════════════════════════════════════════════════════════════

    public function test_v1_accepts_mixed_dates_by_default(): void
    {
        // Requerimiento Jaremar: un manifiesto puede traer facturas de
        // fechas distintas. Se acepta y se fecha al día de carga.
        $payload = [
            $this->invoicePayload([
                'Nfactura' => 'FMIX0001',
                'NumeroManifiesto' => 'MANMIX001',
                'FechaFactura' => '2026-05-20T00:00:00.000Z',
            ]),
            $this->invoicePayload([
                'Nfactura' => 'FMIX0002',
                'NumeroManifiesto' => 'MANMIX001',
                'FechaFactura' => '2026-05-19T00:00:00.000Z', // distinta — permitido
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $manifest = Manifest::where('number', 'MANMIX001')->first();
        $this->assertNotNull($manifest);
        // El manifiesto se fecha al día de carga (hoy), no a las facturas.
        $this->assertSame('2026-05-20', $manifest->date->toDateString());
        $this->assertSame(2, Invoice::whereIn('invoice_number', ['FMIX0001', 'FMIX0002'])->count());
    }

    public function test_v1_legacy_rejects_mixed_dates_when_flag_enabled(): void
    {
        // Modo estricto opcional (reversible por config).
        config(['manifests.dates.reject_mixed_dates' => true]);

        $payload = [
            $this->invoicePayload([
                'Nfactura' => 'FMIX0001',
                'NumeroManifiesto' => 'MANMIX001',
                'FechaFactura' => '2026-05-20T00:00:00.000Z',
            ]),
            $this->invoicePayload([
                'Nfactura' => 'FMIX0002',
                'NumeroManifiesto' => 'MANMIX001',
                'FechaFactura' => '2026-05-19T00:00:00.000Z',
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJsonPath('manifiestos_rechazados.0.motivo', 'FECHAS_MEZCLADAS');
        $response->assertJsonPath('manifiestos_rechazados.0.manifiesto', 'MANMIX001');

        $this->assertSame(0, Manifest::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, ApiInvoiceImport::count());
    }

    public function test_v1_accepts_homogeneous_manifest_with_multiple_invoices(): void
    {
        $payload = [
            $this->invoicePayload([
                'Nfactura' => 'FHOMO001',
                'NumeroManifiesto' => 'MANHOMO001',
                'FechaFactura' => '2026-05-20T00:00:00.000Z',
            ]),
            $this->invoicePayload([
                'Nfactura' => 'FHOMO002',
                'NumeroManifiesto' => 'MANHOMO001',
                'FechaFactura' => '2026-05-20T00:00:00.000Z',
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertSame(1, Manifest::where('number', 'MANHOMO001')->count());
        $this->assertSame(2, Invoice::whereIn('invoice_number', ['FHOMO001', 'FHOMO002'])->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V2 — FECHA_FACTURA_FUTURA
    // ═══════════════════════════════════════════════════════════════════

    public function test_v2_rejects_batch_with_future_dated_invoices(): void
    {
        $payload = [$this->invoicePayload([
            'Nfactura' => 'FFUT0001',
            'NumeroManifiesto' => 'MANFUT001',
            'FechaFactura' => '2026-05-25T00:00:00.000Z', // hoy + 5 días
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson(['motivo' => 'FECHAS_INVALIDAS']);
        $response->assertJsonPath('manifiestos_rechazados.0.motivo', 'FECHA_FACTURA_FUTURA');
        $response->assertJsonPath('manifiestos_rechazados.0.detalle.hoy_servidor', '2026-05-20');
        $response->assertJsonPath('manifiestos_rechazados.0.detalle.facturas_futuras.FFUT0001', '2026-05-25');

        $this->assertSame(0, Manifest::count());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V3 — FECHA_FACTURA_DEMASIADO_ANTIGUA
    // ═══════════════════════════════════════════════════════════════════

    public function test_v3_rejects_batch_older_than_max_backdate_days(): void
    {
        // Hoy = 2026-05-20. Hace 45 días = 2026-04-05. Excede 30 → rechazo.
        $payload = [$this->invoicePayload([
            'Nfactura' => 'FOLD0001',
            'NumeroManifiesto' => 'MANOLD001',
            'FechaFactura' => '2026-04-05T00:00:00.000Z',
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson(['motivo' => 'FECHAS_INVALIDAS']);
        $response->assertJsonPath('manifiestos_rechazados.0.motivo', 'FECHA_FACTURA_DEMASIADO_ANTIGUA');
        $response->assertJsonPath('manifiestos_rechazados.0.detalle.limite_dias', 30);
        $response->assertJsonPath('manifiestos_rechazados.0.detalle.facturas_antiguas.FOLD0001.dias', 45);

        $this->assertSame(0, Manifest::count());
    }

    public function test_v3_accepts_retroactive_within_threshold(): void
    {
        // 10 días atrás está dentro del límite (30) → debe aceptarse.
        $payload = [$this->invoicePayload([
            'Nfactura' => 'FRETRO001',
            'NumeroManifiesto' => 'MANRETRO001',
            'FechaFactura' => '2026-05-10T00:00:00.000Z',
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $manifest = Manifest::where('number', 'MANRETRO001')->first();
        $this->assertNotNull($manifest);
        // Default 'upload': el manifiesto se fecha al DÍA DE CARGA (hoy),
        // aunque la factura sea de 10 días atrás. La factura conserva su
        // propia invoice_date.
        $this->assertSame('2026-05-20', $manifest->date->toDateString());
        $this->assertSame('2026-05-10', Invoice::where('invoice_number', 'FRETRO001')->first()->invoice_date->toDateString());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V4 — manifests.date = día de carga (default 'upload')
    // ═══════════════════════════════════════════════════════════════════

    public function test_manifest_is_dated_to_upload_day_even_for_prior_day_invoices(): void
    {
        // Facturas de 8 días atrás, subidas hoy → el manifiesto queda
        // fechado HOY (día de carga). La factura conserva su fecha real.
        $payload = [$this->invoicePayload([
            'Nfactura' => 'FUP0001',
            'NumeroManifiesto' => 'MANUP001',
            'FechaFactura' => '2026-05-12T00:00:00.000Z',
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(200);
        $manifest = Manifest::where('number', 'MANUP001')->first();
        $this->assertSame('2026-05-20', $manifest->date->toDateString());
    }

    public function test_legacy_invoice_date_source_logs_retroactive_metadata(): void
    {
        // Modo legacy (manifest_date_source='invoice'): el manifiesto se
        // fecha por la FechaFactura y el activity log captura los metadatos
        // retroactivos.
        config(['manifests.dates.manifest_date_source' => 'invoice']);

        $payload = [$this->invoicePayload([
            'Nfactura' => 'FACTLOG001',
            'NumeroManifiesto' => 'MANACTLOG001',
            'FechaFactura' => '2026-05-15T00:00:00.000Z', // 5 días atrás
        ])];

        $this->postInsertar($payload)->assertStatus(200);

        $manifest = Manifest::where('number', 'MANACTLOG001')->first();
        $this->assertNotNull($manifest);
        $this->assertSame('2026-05-15', $manifest->date->toDateString());

        $activity = $manifest->activities()
            ->where('log_name', 'api')
            ->where('description', 'like', 'Manifiesto %creado via API%')
            ->first();

        $this->assertNotNull($activity);
        $props = $activity->properties->toArray();
        $this->assertSame('jaremar_api', $props['source']);
        $this->assertSame('2026-05-15', $props['operation_date']);
        $this->assertSame(5, $props['retroactive_days']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Notificaciones a admins
    // ═══════════════════════════════════════════════════════════════════

    public function test_date_rejection_does_not_notify_admins_by_default(): void
    {
        // Default: los rechazos por fecha NO generan notificación in-app
        // (son problema de datos de Jaremar, no de Hosana). El log y la
        // respuesta del API sí quedan.
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [$this->invoicePayload([
            'Nfactura' => 'FNOTIF001',
            'NumeroManifiesto' => 'MANNOTIF001',
            'FechaFactura' => '2026-05-25T00:00:00.000Z', // futuro
        ])];

        $this->postInsertar($payload)->assertStatus(422);

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_date_rejection_notifies_admins_when_flag_enabled(): void
    {
        // Con el flag activado, sí se notifica a los admins.
        config(['manifests.dates.notify_admins_on_date_rejection' => true]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [$this->invoicePayload([
            'Nfactura' => 'FNOTIF001',
            'NumeroManifiesto' => 'MANNOTIF001',
            'FechaFactura' => '2026-05-25T00:00:00.000Z', // futuro
        ])];

        $this->postInsertar($payload)->assertStatus(422);

        $this->assertSame(1, $admin->notifications()->count());

        $serialized = json_encode($admin->notifications()->first()->data);
        $this->assertStringContainsString('MANNOTIF001', $serialized);
        $this->assertStringContainsString('fecha futura', $serialized);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Mezcla de manifiestos válidos e inválidos en el mismo batch
    // ═══════════════════════════════════════════════════════════════════

    public function test_batch_with_mixed_valid_and_invalid_manifests_rejects_all(): void
    {
        $payload = [
            // Manifest válido
            $this->invoicePayload([
                'Nfactura' => 'FMIX001',
                'NumeroManifiesto' => 'MANVAL001',
                'FechaFactura' => '2026-05-20T00:00:00.000Z',
            ]),
            // Manifest inválido (futuro)
            $this->invoicePayload([
                'Nfactura' => 'FMIX002',
                'NumeroManifiesto' => 'MANINV001',
                'FechaFactura' => '2026-06-01T00:00:00.000Z',
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson([
            'resumen' => [
                'total_recibidas' => 2,
                'total_rechazadas' => 1,
                'total_validas' => 1,
                'insertadas' => 0,
            ],
        ]);

        // El controller reporta ambos para que Jaremar pueda reenviar el válido.
        // Etiqueta unificada: los válidos viajan en manifiestos[].
        $response->assertJsonPath('manifiestos.0.manifiesto', 'MANVAL001');
        $response->assertJsonPath('manifiestos.0.total_facturas', 1);
        $response->assertJsonPath('manifiestos_rechazados.0.manifiesto', 'MANINV001');

        // Nada se persistió.
        $this->assertSame(0, Manifest::count());
        $this->assertSame(0, Invoice::count());
    }
}
