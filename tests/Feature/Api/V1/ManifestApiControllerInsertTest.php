<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiInvoiceImport;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests end-to-end para POST /api/v1/facturas/insertar.
 *
 * Este es el endpoint MÁS CRÍTICO del sistema: es la puerta por la que
 * Jaremar empuja todos los días cientos de facturas reales de las 3
 * bodegas. Si se rompe, se paran las ventas.
 *
 * Los unit tests del service (ApiInvoiceImporterServiceParseDateTest /
 * ValidateWarehousesTest) cubren helpers aislados; acá validamos el
 * pipeline completo: auth → validación de estructura → fechas → hash
 * dedup → persistencia → recálculo de totales, tal como lo ejerce
 * Jaremar en producción.
 *
 * Todos los tests golpean Postgres real con RefreshDatabase.
 */
class ManifestApiControllerInsertTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-hozana-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        // Configurar API key de prueba y rate limit alto para no tropezar
        // con el throttling durante los tests (excepto el test dedicado
        // de rate limit que lo baja explícitamente).
        config([
            'api.jaremar_api_key'              => self::API_KEY,
            'api.rate_limit_insertar_per_minute' => 100,
            'api.rate_limit_per_minute'          => 100,
        ]);

        // Roles de Spatie: el controller llama User::role(['super_admin',
        // 'admin']) en notifyAdmins() cuando hay rechazos. Si los roles
        // no existen en BD, Spatie lanza RoleDoesNotExist y tumba toda la
        // request. En producción se crean via RoleSeeder; acá los creamos
        // a mano para mantener el test independiente del seeder.
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        // Supplier activo: el importador requiere al menos uno para
        // crear manifiestos nuevos via createManifest().
        Supplier::factory()->create(['is_active' => true]);

        // Las 3 bodegas reales de Hozana. El service las cachea al
        // construirse, pero Laravel crea una instancia nueva por request
        // en los tests (no está bound como singleton), así que crearlas
        // antes del primer POST es suficiente.
        Warehouse::factory()->oac()->create();
        Warehouse::factory()->oas()->create();
        Warehouse::factory()->oao()->create();
    }

    /**
     * Construye una factura mínima válida según el contrato de Jaremar
     * (ver ApiInvoiceValidatorService::$requiredInvoiceFields).
     */
    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'Nfactura'         => 'F' . fake()->unique()->numerify('########'),
            'NumeroManifiesto' => 'MAN100001',
            'Total'            => 450.0,
            'FechaFactura'     => now()->toIso8601String(),
            'Almacen'          => 'OAC',
            'Vendedorid'       => 'V01',
            'Vendedor'         => 'VENDEDOR PRUEBA',
            'Clienteid'        => 'C001',
            'Cliente'          => 'PULPERIA PRUEBA',
            'Rtn'              => '',
            'TipoPago'         => 'CONTADO',
            'DiasCred'         => 0,
            'TipoFactura'      => 'FAC',
            'EstadoFactura'    => 1,
            'NumeroFacturaLX'  => 'LX' . fake()->unique()->numerify('######'),
            'NumeroPedido'     => 'PED' . fake()->unique()->numerify('######'),
            'NumeroRuta'       => '001',
            'Direccion'        => 'COL. TEST, TEGUCIGALPA',
            'EntregarA'        => 'PULPERIA PRUEBA',
            'LineasFactura'    => [[
                'ProductoId'   => 'ART-TEST-001',
                'ProductoDesc' => 'PRODUCTO DE PRUEBA',
                'NumeroLinea'  => 1,
                'Total'        => 450.0,
                'Precio'       => 15.0,
                'Subtotal'     => 450.0,
                'Costo'        => 0.0,
                'CantidadFracciones' => 30.0,
                'CantidadDecimal'    => 30.0,
                'CantidadCaja'       => 0.0,
                'FactorConversion'   => 1,
                'UniVenta'           => 'UN',
                'TipoProducto'       => 'A',
                'Descuento'          => 0.0,
                'Impuesto'           => 0.0,
                'Impuesto18'         => 0.0,
                'PorcentajeDescuento' => 0.0,
                'PorcentajeImpuesto'  => 0.0,
                'CantidadUnidadMinVenta' => 30.0,
                'PrecioUnidadMinVenta'   => 15.0,
                'Peso'    => 0.0,
                'Volumen' => 0.0,
                'Id'      => fake()->unique()->numberBetween(1, 999999),
                'InvoiceId' => fake()->numberBetween(1, 999999),
            ]],
        ], $overrides);
    }

    /**
     * POST al endpoint con la API key correcta. Helper para no repetir
     * el header en cada test.
     */
    private function postInsertar(array $payload, ?string $apiKey = self::API_KEY)
    {
        $headers = $apiKey !== null ? ['ApiKey' => $apiKey] : [];
        return $this->postJson('/api/v1/facturas/insertar', $payload, $headers);
    }

    // ── Auth ────────────────────────────────────────────────────────────

    public function test_insert_requires_api_key_header(): void
    {
        $response = $this->postInsertar([$this->invoicePayload()], apiKey: null);

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
    }

    public function test_insert_rejects_invalid_api_key(): void
    {
        $response = $this->postInsertar([$this->invoicePayload()], apiKey: 'wrong-key');

        $response->assertStatus(401);
        $response->assertJson(['success' => false]);
    }

    // ── Estructura del payload ──────────────────────────────────────────

    public function test_insert_rejects_empty_array_body(): void
    {
        $response = $this->postInsertar([]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_insert_rejects_payload_missing_required_fields(): void
    {
        // Factura con Nfactura / NumeroManifiesto / LineasFactura vacíos → el
        // validator debe devolver errores y el controller debe rechazar
        // ANTES de tocar la BD.
        //
        // NOTA: Enviamos las claves presentes pero con valor null para no
        // disparar el warning "Undefined array key" de PHP 8+ dentro del
        // validator (que usa empty($invoice[$field])). En producción el
        // cliente Jaremar siempre envía el JSON completo con los campos
        // definidos; lo que validamos acá es que el server rechaza valores
        // vacíos, que es el caso realista.
        $badInvoice = [
            'Nfactura'         => null,
            'NumeroManifiesto' => null,
            'LineasFactura'    => null,
            'Total'            => 100,
            'Almacen'          => 'OAC',
            'Cliente'          => 'X',
            'Clienteid'        => 'C1',
            'Vendedorid'       => 'V01',
            'FechaFactura'     => now()->toDateString(),
        ];

        $response = $this->postInsertar([$badInvoice]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonStructure(['success', 'message', 'errores']);

        // Garantía de aislamiento: no se debe haber creado NINGÚN registro.
        $this->assertSame(0, Manifest::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, ApiInvoiceImport::count());
    }

    // ── Happy path ──────────────────────────────────────────────────────

    public function test_insert_creates_new_manifest_with_invoices_and_lines(): void
    {
        $payload = [$this->invoicePayload([
            'Nfactura'         => 'F99100001',
            'NumeroManifiesto' => 'MAN999001',
            'Total'            => 450.0,
            'Almacen'          => 'OAC',
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'resumen' => [
                'recibidas'  => 1,
                'insertadas' => 1,
            ],
        ]);

        // Manifiesto creado
        $manifest = Manifest::where('number', 'MAN999001')->first();
        $this->assertNotNull($manifest);
        $this->assertSame('imported', $manifest->status);
        // Totales recalculados tras el insert
        $this->assertEqualsWithDelta(450.0, (float) $manifest->total_invoices, 0.01);
        $this->assertSame(1, $manifest->invoices_count);

        // Factura + línea persistidas
        $invoice = Invoice::where('invoice_number', 'F99100001')->first();
        $this->assertNotNull($invoice);
        $this->assertSame($manifest->id, $invoice->manifest_id);
        $this->assertSame('imported', $invoice->status);
        $this->assertSame(1, InvoiceLine::where('invoice_id', $invoice->id)->count());

        // Registro de auditoría del batch
        $import = ApiInvoiceImport::first();
        $this->assertNotNull($import);
        $this->assertSame('processed', $import->status);
        $this->assertSame(1, $import->invoices_inserted);
    }

    public function test_insert_processes_multi_manifest_multi_warehouse_batch(): void
    {
        // Un batch real de Jaremar suele traer facturas de varios
        // manifiestos a la vez, repartidas entre las 3 bodegas. Este test
        // valida que el importer agrupa correctamente por manifiesto y
        // crea un manifiesto por número distinto.
        $payload = [
            $this->invoicePayload([
                'Nfactura' => 'F10000001', 'NumeroManifiesto' => 'MAN001',
                'Almacen'  => 'OAC', 'Total' => 1000.0,
                'LineasFactura' => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 1000.0])],
            ]),
            $this->invoicePayload([
                'Nfactura' => 'F10000002', 'NumeroManifiesto' => 'MAN001',
                'Almacen'  => 'OAS', 'Total' => 500.0,
                'LineasFactura' => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 500.0])],
            ]),
            $this->invoicePayload([
                'Nfactura' => 'F10000003', 'NumeroManifiesto' => 'MAN002',
                'Almacen'  => 'OAO', 'Total' => 800.0,
                'LineasFactura' => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 800.0])],
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Se crearon 2 manifiestos distintos
        $this->assertSame(2, Manifest::whereIn('number', ['MAN001', 'MAN002'])->count());

        $man1 = Manifest::where('number', 'MAN001')->first();
        $this->assertEqualsWithDelta(1500.0, (float) $man1->total_invoices, 0.01);
        $this->assertSame(2, $man1->invoices_count);

        $man2 = Manifest::where('number', 'MAN002')->first();
        $this->assertEqualsWithDelta(800.0, (float) $man2->total_invoices, 0.01);
        $this->assertSame(1, $man2->invoices_count);

        // El endpoint devuelve la lista de manifiestos tocados para que
        // Jaremar pueda hacer seguimiento.
        $response->assertJson(['manifiestos' => ['MAN001', 'MAN002']]);
    }

    public function test_insert_is_idempotent_for_identical_batch_same_day(): void
    {
        // Regresión protegida: si Jaremar reintenta el mismo POST (por
        // timeout o error de red), el hash detector debe devolver 200
        // con el resumen del batch original SIN insertar nada nuevo.
        $payload = [$this->invoicePayload([
            'Nfactura'         => 'FDUP0001',
            'NumeroManifiesto' => 'MANDUP001',
            'Total'            => 200.0,
        ])];

        $first = $this->postInsertar($payload);
        $first->assertStatus(200);
        $firstBatchUuid = $first->json('batch_uuid');

        $second = $this->postInsertar($payload);
        $second->assertStatus(200);
        $second->assertJson(['success' => true]);

        // Mismo batch_uuid → confirma que golpeó el short-circuit del
        // hash detector y no creó un ApiInvoiceImport nuevo.
        $this->assertSame($firstBatchUuid, $second->json('batch_uuid'));
        $this->assertSame(1, ApiInvoiceImport::count());
        $this->assertSame(1, Invoice::where('invoice_number', 'FDUP0001')->count());
    }

    public function test_insert_returns_conflict_for_existing_invoice_with_changes(): void
    {
        // Primer POST: factura nueva.
        $first = [$this->invoicePayload([
            'Nfactura'         => 'FCONF0001',
            'NumeroManifiesto' => 'MANCONF001',
            'Total'            => 500.0,
            'LineasFactura'    => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 500.0])],
        ])];
        $this->postInsertar($first)->assertStatus(200);

        // Segundo POST: misma factura con Total distinto → debe generar
        // un conflict y terminar en invoices_pending_review, NO
        // sobrescribir la factura original.
        $second = [$this->invoicePayload([
            'Nfactura'         => 'FCONF0001',
            'NumeroManifiesto' => 'MANCONF001',
            'Total'            => 999.0,  // ← cambió
            'LineasFactura'    => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 999.0])],
        ])];
        $response = $this->postInsertar($second);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'resumen' => [
                'insertadas'          => 0,
                'pendientes_revision' => 1,
            ],
        ]);

        // La factura en BD mantiene el total original (500) — el nuevo
        // valor queda en ApiInvoiceImportConflict esperando revisión.
        $invoice = Invoice::where('invoice_number', 'FCONF0001')->first();
        $this->assertEqualsWithDelta(500.0, (float) $invoice->total, 0.01);
    }

    // ── Rechazos ────────────────────────────────────────────────────────

    public function test_insert_rejects_entire_batch_when_almacen_is_unknown(): void
    {
        // El manifiesto no debe crearse si TODO el manifiesto tiene un
        // Almacén desconocido — rechazo total del grupo de ese manifiesto
        // con 422 ALMACENES_DESCONOCIDOS.
        $payload = [$this->invoicePayload([
            'Nfactura'         => 'FUNK0001',
            'NumeroManifiesto' => 'MANUNK001',
            'Almacen'          => 'ZZZ',  // ← no existe en warehouseMap
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo'  => 'ALMACENES_DESCONOCIDOS',
        ]);

        // El manifest SÍ se creó (porque el controller crea el record de
        // manifest antes de validar las filas — ver ApiInvoiceImporterService::
        // processManifestGroup). Pero no debe haber ninguna factura insertada.
        $this->assertSame(0, Invoice::where('invoice_number', 'FUNK0001')->count());
    }

    public function test_insert_rejects_invoices_for_closed_manifest(): void
    {
        // Pre-crear un manifiesto cerrado con número conocido. Las
        // facturas del batch apuntan a ese número → todas rechazadas.
        $closed = Manifest::factory()->closed()->create([
            'number' => 'MANCLOSED',
        ]);

        $payload = [$this->invoicePayload([
            'Nfactura'         => 'FCLOSED01',
            'NumeroManifiesto' => 'MANCLOSED',
            'Total'            => 300.0,
        ])];

        $response = $this->postInsertar($payload);

        // El controller aún devuelve 200 porque el problema es a nivel
        // factura individual, no del batch completo. Pero el resumen
        // refleja 0 insertadas + 1 rechazada, con detalle en errors.
        $response->assertStatus(200);
        $response->assertJson([
            'resumen' => [
                'insertadas' => 0,
                'rechazadas' => 1,
            ],
        ]);
        $this->assertNotEmpty($response->json('rechazadas_detalle'));

        // El manifiesto cerrado NO recibió la factura
        $this->assertSame(0, Invoice::where('manifest_id', $closed->id)->count());
    }

    public function test_insert_rejects_entire_batch_when_manifest_is_from_past_day(): void
    {
        // Pre-crear un manifiesto con created_at de ayer. Regla de negocio
        // crítica: Jaremar NO puede agregar facturas nuevas a un manifiesto
        // que fue creado en un día anterior — eso indicaría que el OPL
        // mandó datos fuera de ventana.
        Manifest::factory()->create([
            'number'     => 'MANPAST001',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $payload = [$this->invoicePayload([
            'Nfactura'         => 'FPAST0001',
            'NumeroManifiesto' => 'MANPAST001',
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo'  => 'MANIFIESTOS_FECHA_INVALIDA',
        ]);
        $response->assertJsonStructure([
            'manifiestos_rechazados' => [
                '*' => ['manifiesto', 'fecha_original', 'fecha_intento'],
            ],
        ]);

        // Confirma que NADA del batch entró — el rechazo por fecha es total.
        $this->assertSame(0, Invoice::where('invoice_number', 'FPAST0001')->count());
        $this->assertSame(0, ApiInvoiceImport::count());
    }

    public function test_insert_past_date_rejection_also_lists_valid_manifests_for_resend(): void
    {
        // Cuando un batch mezcla un manifiesto de día anterior con uno
        // nuevo válido, Jaremar necesita saber QUÉ manifiestos reenviar
        // en un batch separado. El controller debe listar ambos grupos.
        Manifest::factory()->create([
            'number'     => 'MANPAST002',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $payload = [
            $this->invoicePayload([
                'Nfactura' => 'FMIX0001', 'NumeroManifiesto' => 'MANPAST002',
            ]),
            $this->invoicePayload([
                'Nfactura' => 'FMIX0002', 'NumeroManifiesto' => 'MANNUEVO',
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo'  => 'MANIFIESTOS_FECHA_INVALIDA',
        ]);
        // Cantidades cuadran: 2 recibidas, 1 rechazada, 1 válida pero
        // no insertada porque todo el batch falla.
        $response->assertJson([
            'resumen' => [
                'total_recibidas'  => 2,
                'total_rechazadas' => 1,
                'total_validas'    => 1,
                'insertadas'       => 0,
            ],
        ]);
        $this->assertNotEmpty($response->json('manifiestos_no_afectados'));
        $this->assertSame(
            'MANNUEVO',
            $response->json('manifiestos_no_afectados.0.manifiesto')
        );
    }

    // ── Rate limiting ───────────────────────────────────────────────────

    public function test_insert_rate_limit_returns_429_after_configured_limit(): void
    {
        // Bajar el límite a 1/min y hacer 2 POST — el segundo debe 429.
        // Es el único test que valida el throttle:insertar end-to-end.
        config(['api.rate_limit_insertar_per_minute' => 1]);

        $payload = [$this->invoicePayload([
            'Nfactura'         => 'FRL0001',
            'NumeroManifiesto' => 'MANRL001',
        ])];

        $this->postInsertar($payload)->assertStatus(200);

        $second = $this->postInsertar([$this->invoicePayload([
            'Nfactura'         => 'FRL0002',
            'NumeroManifiesto' => 'MANRL002',
        ])]);

        $second->assertStatus(429);
    }
}
