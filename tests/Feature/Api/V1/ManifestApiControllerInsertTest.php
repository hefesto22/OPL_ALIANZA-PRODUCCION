<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiInvoiceImport;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
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
            'api.jaremar_api_key' => self::API_KEY,
            'api.rate_limit_insertar_per_minute' => 100,
            'api.rate_limit_per_minute' => 100,
        ]);

        // Roles de Spatie: el controller llama User::role(['super_admin',
        // 'admin']) en notifyAdmins() cuando hay rechazos. Si los roles
        // no existen en BD, Spatie lanza RoleDoesNotExist y tumba toda la
        // request. En producción se crean via RoleSeeder; acá los creamos
        // a mano para mantener el test independiente del seeder.
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        // Un usuario super_admin REAL: sin él, notifyAdmins() retorna temprano
        // (User::role([...])->isEmpty()) y nunca ejecuta la construcción del
        // mensaje por motivo. Con él, TODO test de rechazo ejercita esa ruta
        // — así reproducimos el 500 que ocurría en producción cuando el motivo
        // era FACTURAS_YA_EXISTENTES / FACTURAS_DUPLICADAS_EN_OTRO_MANIFIESTO
        // (motivos sin la clave 'almacenes_desconocidos' que asumía el código).
        User::factory()->create()->assignRole('super_admin');

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
            'Nfactura' => 'F'.fake()->unique()->numerify('########'),
            'NumeroManifiesto' => 'MAN100001',
            'Total' => 450.0,
            'FechaFactura' => now()->toIso8601String(),
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
            'Nfactura' => null,
            'NumeroManifiesto' => null,
            'LineasFactura' => null,
            'Total' => 100,
            'Almacen' => 'OAC',
            'Cliente' => 'X',
            'Clienteid' => 'C1',
            'Vendedorid' => 'V01',
            'FechaFactura' => now()->toDateString(),
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
            'Nfactura' => 'F99100001',
            'NumeroManifiesto' => 'MAN999001',
            'Total' => 450.0,
            'Almacen' => 'OAC',
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'resumen' => [
                'recibidas' => 1,
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
                'Almacen' => 'OAC', 'Total' => 1000.0,
                'LineasFactura' => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 1000.0])],
            ]),
            $this->invoicePayload([
                'Nfactura' => 'F10000002', 'NumeroManifiesto' => 'MAN001',
                'Almacen' => 'OAS', 'Total' => 500.0,
                'LineasFactura' => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 500.0])],
            ]),
            $this->invoicePayload([
                'Nfactura' => 'F10000003', 'NumeroManifiesto' => 'MAN002',
                'Almacen' => 'OAO', 'Total' => 800.0,
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
        // Jaremar pueda hacer seguimiento (lista de números de manifiesto).
        $response->assertJson(['manifiestos' => ['MAN001', 'MAN002']]);
    }

    /**
     * Unificación de nombre solicitada por SAP/Jaremar: el listado de
     * manifiestos válidos debe llamarse SIEMPRE `manifiestos`, con la misma
     * forma que el OK (lista de números de manifiesto), tanto en la
     * respuesta de éxito (200) como en las de rechazo (422). Antes los
     * errores usaban `manifiestos_validos` / `manifiestos_no_afectados`;
     * esas etiquetas ya no deben existir.
     */
    public function test_manifiestos_label_is_unified_string_list_in_success_and_rejection(): void
    {
        // ── Éxito (200): manifiestos = lista de números ────────────────
        $ok = $this->postInsertar([$this->invoicePayload([
            'Nfactura' => 'FUNIF0001',
            'NumeroManifiesto' => 'MANUNIF-OK',
        ])]);

        $ok->assertStatus(200);
        $ok->assertJson(['manifiestos' => ['MANUNIF-OK']]);

        // ── Rechazo por fecha (422): misma etiqueta, misma forma ───────
        // Mezcla un manifiesto válido con uno de fecha futura (V2): el
        // batch se rechaza completo pero el válido viaja en manifiestos[]
        // como lista de números, igual que en el éxito.
        $rejected = $this->postInsertar([
            $this->invoicePayload([
                'Nfactura' => 'FUNIF0002',
                'NumeroManifiesto' => 'MANUNIF-VALIDO',
                'FechaFactura' => now()->toIso8601String(),
            ]),
            $this->invoicePayload([
                'Nfactura' => 'FUNIF0003',
                'NumeroManifiesto' => 'MANUNIF-FUTURO',
                'FechaFactura' => now()->addDays(5)->toIso8601String(),
            ]),
        ]);

        $rejected->assertStatus(422);
        $rejected->assertJson(['manifiestos' => ['MANUNIF-VALIDO']]);

        // Las etiquetas viejas ya no deben existir en ninguna respuesta.
        $this->assertNull($rejected->json('manifiestos_validos'));
        $this->assertNull($rejected->json('manifiestos_no_afectados'));
    }

    public function test_insert_is_idempotent_for_identical_batch_same_day(): void
    {
        // Regresión protegida: si Jaremar reintenta el mismo POST (por
        // timeout o error de red), el hash detector debe devolver 200
        // con el resumen del batch original SIN insertar nada nuevo.
        $payload = [$this->invoicePayload([
            'Nfactura' => 'FDUP0001',
            'NumeroManifiesto' => 'MANDUP001',
            'Total' => 200.0,
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

    public function test_insert_is_idempotent_for_identical_batch_from_a_previous_day(): void
    {
        // Regresión del 500 en producción (25-jun-2026): cuando Jaremar
        // reenvía un payload idéntico cuyo import original NO es de hoy, el
        // pre-chequeo filtraba por fecha y no lo encontraba → el INSERT
        // chocaba con el índice único parcial GLOBAL y devolvía un 500.
        // Ahora el pre-chequeo es global (sin filtro de fecha) y, además, el
        // catch de la carrera responde idempotente. Debe dar 200, no 500.
        $payload = [$this->invoicePayload([
            'Nfactura' => 'FPREV0001',
            'NumeroManifiesto' => 'MANPREV01',
            'Total' => 150.0,
        ])];

        $first = $this->postInsertar($payload);
        $first->assertStatus(200);
        $firstBatchUuid = $first->json('batch_uuid');

        // Simulamos que ese import fue de un día anterior.
        ApiInvoiceImport::query()->update(['created_at' => now()->subDays(2)]);

        // Reenvío idéntico: NO debe dar 500, debe responder 200 idempotente
        // con el mismo batch original.
        $second = $this->postInsertar($payload);
        $second->assertStatus(200);
        $second->assertJson(['success' => true]);
        $this->assertSame($firstBatchUuid, $second->json('batch_uuid'));

        // No se creó un import nuevo ni se duplicó la factura.
        $this->assertSame(1, ApiInvoiceImport::count());
        $this->assertSame(1, Invoice::where('invoice_number', 'FPREV0001')->count());
    }

    public function test_insert_rejects_batch_when_invoice_already_exists_with_changes(): void
    {
        // Estricto total: una factura que YA existe (aunque venga con cambios)
        // NO genera conflicto ni se aplica parcial — rechaza el lote completo
        // con 422 FACTURAS_YA_EXISTENTES y deja la original intacta.
        $first = [$this->invoicePayload([
            'Nfactura' => 'FCONF0001',
            'NumeroManifiesto' => 'MANCONF001',
            'Total' => 500.0,
            'LineasFactura' => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 500.0])],
        ])];
        $this->postInsertar($first)->assertStatus(200);

        // Segundo POST: misma factura con Total distinto (hash distinto, no
        // entra por idempotencia) → la factura ya existe → rechazo total.
        $second = [$this->invoicePayload([
            'Nfactura' => 'FCONF0001',
            'NumeroManifiesto' => 'MANCONF001',
            'Total' => 999.0,  // ← cambió
            'LineasFactura' => [array_merge($this->invoicePayload()['LineasFactura'][0], ['Total' => 999.0])],
        ])];
        $response = $this->postInsertar($second);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo' => 'FACTURAS_YA_EXISTENTES',
            'resumen' => [
                'insertadas' => 0,
                'rechazadas' => 1,
            ],
        ]);
        $response->assertJsonPath('manifiestos_rechazados.0.motivo', 'FACTURAS_YA_EXISTENTES');
        $response->assertJsonPath('manifiestos_rechazados.0.facturas_existentes.0.factura', 'FCONF0001');

        // La factura en BD mantiene el total original (500) — nada se tocó.
        $invoice = Invoice::where('invoice_number', 'FCONF0001')->first();
        $this->assertEqualsWithDelta(500.0, (float) $invoice->total, 0.01);

        // No se crea ningún conflicto (la función de conflictos ya no aplica).
        $this->assertSame(0, \App\Models\ApiInvoiceImportConflict::count());
    }

    // ── Rechazos ────────────────────────────────────────────────────────

    public function test_insert_rejects_entire_batch_when_almacen_is_unknown(): void
    {
        // El manifiesto no debe crearse si TODO el manifiesto tiene un
        // Almacén desconocido — rechazo total del grupo de ese manifiesto
        // con 422 ALMACENES_DESCONOCIDOS.
        $payload = [$this->invoicePayload([
            'Nfactura' => 'FUNK0001',
            'NumeroManifiesto' => 'MANUNK001',
            'Almacen' => 'ZZZ',  // ← no existe en warehouseMap
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo' => 'ALMACENES_DESCONOCIDOS',
        ]);

        // El manifest SÍ se creó (porque el controller crea el record de
        // manifest antes de validar las filas — ver ApiInvoiceImporterService::
        // processManifestGroup). Pero no debe haber ninguna factura insertada.
        $this->assertSame(0, Invoice::where('invoice_number', 'FUNK0001')->count());
    }

    public function test_insert_rejects_invoices_for_closed_manifest(): void
    {
        // Pre-crear un manifiesto cerrado con número conocido. Las
        // facturas del batch apuntan a ese número → manifiesto entero
        // rechazado (formato agrupado, consistente con almacén desconocido).
        $closed = Manifest::factory()->closed()->create([
            'number' => 'MANCLOSED',
        ]);

        $payload = [$this->invoicePayload([
            'Nfactura' => 'FCLOSED01',
            'NumeroManifiesto' => 'MANCLOSED',
            'Total' => 300.0,
        ])];

        $response = $this->postInsertar($payload);

        // 422 + motivo MANIFIESTO_CERRADO + manifiestos_rechazados[]
        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo' => 'MANIFIESTO_CERRADO',
            'resumen' => [
                'insertadas' => 0,
                'rechazadas' => 1,
            ],
        ]);
        $response->assertJsonPath('manifiestos_rechazados.0.manifiesto', 'MANCLOSED');
        $response->assertJsonPath('manifiestos_rechazados.0.motivo', 'MANIFIESTO_CERRADO');
        $response->assertJsonPath('manifiestos_rechazados.0.total_facturas', 1);

        // El manifiesto cerrado NO recibió la factura
        $this->assertSame(0, Invoice::where('manifest_id', $closed->id)->count());
    }

    public function test_insert_rejects_entire_manifest_when_any_invoice_duplicated_in_other_manifest(): void
    {
        // Pre-existir un manifiesto previo con factura FDUP-API que viene
        // a colisionar. Cualquier intento de meter esa factura en otro
        // manifiesto debe rechazar el manifiesto NUEVO entero (no solo
        // esa factura). Forzamos a Jaremar a reenviar el manifiesto limpio.
        $supplier = Supplier::factory()->create();
        $oac = Warehouse::query()->where('code', 'OAC')->first();
        $manifestPrev = Manifest::factory()->create([
            'number' => 'MAN-PREV-API',
            'supplier_id' => $supplier->id,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifestPrev->id,
            'warehouse_id' => $oac->id,
            'invoice_number' => 'FDUP-API',
        ]);

        // Batch nuevo: 1 factura limpia + 1 duplicada, ambas para MAN-NEW.
        $payload = [
            $this->invoicePayload([
                'Nfactura' => 'FDUP-API',
                'NumeroManifiesto' => 'MAN-NEW',
            ]),
            $this->invoicePayload([
                'Nfactura' => 'F-CLEAN-NEW',
                'NumeroManifiesto' => 'MAN-NEW',
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo' => 'FACTURAS_DUPLICADAS_EN_OTRO_MANIFIESTO',
            'resumen' => [
                'insertadas' => 0,
                'rechazadas' => 2,
            ],
        ]);
        $response->assertJsonPath('manifiestos_rechazados.0.manifiesto', 'MAN-NEW');
        $response->assertJsonPath('manifiestos_rechazados.0.motivo', 'FACTURAS_DUPLICADAS_EN_OTRO_MANIFIESTO');
        $response->assertJsonPath('manifiestos_rechazados.0.total_facturas', 2);
        $response->assertJsonPath('manifiestos_rechazados.0.facturas_duplicadas.0.factura', 'FDUP-API');
        $response->assertJsonPath('manifiestos_rechazados.0.facturas_duplicadas.0.manifiesto_existente', 'MAN-PREV-API');

        // Ninguna factura del manifiesto nuevo entró (rechazo atómico).
        $this->assertSame(0, Invoice::where('invoice_number', 'F-CLEAN-NEW')->count());
        // La duplicada original sigue intacta en el manifiesto previo.
        $this->assertSame(1, Invoice::where('invoice_number', 'FDUP-API')->where('manifest_id', $manifestPrev->id)->count());
    }

    public function test_insert_rolls_back_entire_batch_when_one_manifest_fails(): void
    {
        // Atomicidad ENTRE manifiestos (todo o nada): un lote con un
        // manifiesto válido y nuevo + uno cerrado NO debe insertar nada,
        // ni siquiera el manifiesto válido.
        Manifest::factory()->closed()->create(['number' => 'MAN-CLOSED-AT']);

        $payload = [
            $this->invoicePayload([
                'Nfactura' => 'F-ATOMIC-OK',
                'NumeroManifiesto' => 'MAN-OK-AT',
            ]),
            $this->invoicePayload([
                'Nfactura' => 'F-ATOMIC-BAD',
                'NumeroManifiesto' => 'MAN-CLOSED-AT',
            ]),
        ];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        // NADA se insertó: ni la factura del manifiesto válido.
        $this->assertSame(0, Invoice::where('invoice_number', 'F-ATOMIC-OK')->count());
        $this->assertSame(0, Invoice::where('invoice_number', 'F-ATOMIC-BAD')->count());
        // El manifiesto válido tampoco se creó (rollback total).
        $this->assertSame(0, Manifest::where('number', 'MAN-OK-AT')->count());
    }

    public function test_insert_rejects_entire_batch_when_manifest_is_from_past_day(): void
    {
        // Pre-crear un manifiesto con created_at de ayer. Regla de negocio
        // crítica: Jaremar NO puede agregar facturas nuevas a un manifiesto
        // que fue creado en un día anterior — eso indicaría que el OPL
        // mandó datos fuera de ventana.
        Manifest::factory()->create([
            'number' => 'MANPAST001',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $payload = [$this->invoicePayload([
            'Nfactura' => 'FPAST0001',
            'NumeroManifiesto' => 'MANPAST001',
        ])];

        $response = $this->postInsertar($payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'motivo' => 'MANIFIESTOS_FECHA_INVALIDA',
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
            'number' => 'MANPAST002',
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
            'motivo' => 'MANIFIESTOS_FECHA_INVALIDA',
        ]);
        // Cantidades cuadran: 2 recibidas, 1 rechazada, 1 válida pero
        // no insertada porque todo el batch falla.
        $response->assertJson([
            'resumen' => [
                'total_recibidas' => 2,
                'total_rechazadas' => 1,
                'total_validas' => 1,
                'insertadas' => 0,
            ],
        ]);
        // Etiqueta unificada: los válidos no insertados viajan en manifiestos[]
        // como lista de números de manifiesto (misma forma que el OK).
        $this->assertNotEmpty($response->json('manifiestos'));
        $this->assertSame('MANNUEVO', $response->json('manifiestos.0'));
    }

    // ── Idempotencia: protección a nivel de motor (race TOCTOU) ─────────

    public function test_payload_hash_unique_constraint_blocks_concurrent_duplicate(): void
    {
        // El check de payload_hash en el controller tiene ventana TOCTOU:
        // dos llamadas concurrentes podrían pasar el SELECT antes de que
        // la primera complete el INSERT. La unique partial constraint a
        // nivel motor cierra esa ventana — el segundo INSERT lanza
        // QueryException. Este test simula la race insertando directo a
        // BD para validar que la constraint protege contra el peor caso.
        $hash = hash('sha256', '{"test":"toctou-payload"}');

        ApiInvoiceImport::create([
            'batch_uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'api_key_hint' => 'abcd1234',
            'ip_address' => '1.2.3.4',
            'total_received' => 1,
            'raw_payload' => ['test' => 'toctou-payload'],
            'payload_hash' => $hash,
            'status' => 'received',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ApiInvoiceImport::create([
            'batch_uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'api_key_hint' => 'abcd1234',
            'ip_address' => '1.2.3.4',
            'total_received' => 1,
            'raw_payload' => ['test' => 'toctou-payload'],
            'payload_hash' => $hash,
            'status' => 'processed',
        ]);
    }

    public function test_payload_hash_unique_constraint_allows_retry_after_failed_import(): void
    {
        // Si un import falla (status='failed'), el partial unique index lo
        // excluye y permite reintentar con el mismo payload. Es necesario
        // para que un bug corregido permita reenviar el batch original
        // sin tener que mutar manualmente el registro fallido.
        $hash = hash('sha256', '{"retry":"after-failure"}');

        ApiInvoiceImport::create([
            'batch_uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'api_key_hint' => 'abcd1234',
            'ip_address' => '1.2.3.4',
            'total_received' => 1,
            'raw_payload' => ['retry' => 'after-failure'],
            'payload_hash' => $hash,
            'status' => 'failed',
            'failure_message' => 'Bug temporal corregido',
        ]);

        // Insertar con el mismo hash pero status válido NO debe fallar.
        $retry = ApiInvoiceImport::create([
            'batch_uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'api_key_hint' => 'abcd1234',
            'ip_address' => '1.2.3.4',
            'total_received' => 1,
            'raw_payload' => ['retry' => 'after-failure'],
            'payload_hash' => $hash,
            'status' => 'processed',
        ]);

        $this->assertSame('processed', $retry->status);
        $this->assertSame(2, ApiInvoiceImport::where('payload_hash', $hash)->count());
    }

    // ── Rate limiting ───────────────────────────────────────────────────

    public function test_insert_rate_limit_returns_429_after_configured_limit(): void
    {
        // Bajar el límite a 1/min y hacer 2 POST — el segundo debe 429.
        // Es el único test que valida el throttle:insertar end-to-end.
        config(['api.rate_limit_insertar_per_minute' => 1]);

        $payload = [$this->invoicePayload([
            'Nfactura' => 'FRL0001',
            'NumeroManifiesto' => 'MANRL001',
        ])];

        $this->postInsertar($payload)->assertStatus(200);

        $second = $this->postInsertar([$this->invoicePayload([
            'Nfactura' => 'FRL0002',
            'NumeroManifiesto' => 'MANRL002',
        ])]);

        $second->assertStatus(429);
    }
}
