<?php

namespace Tests\Feature\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtProductPriceHistory;
use App\Models\Edt\EdtSupplier;
use App\Services\Edt\EdtProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Tests\TestCase;

/**
 * Carga del catálogo desde el Excel de precios del cliente.
 *
 * Las filas imitan la hoja "Pedido EDTH": fila 1 con los porcentajes,
 * fila 2 con encabezados (desde la columna B), y datos debajo. Incluye los
 * casos reales del archivo: código repetido con precio nuevo más abajo,
 * código repetido sin cambios, y fila repetida con la descripción vacía.
 */
class EdtProductImportTest extends TestCase
{
    use RefreshDatabase;

    private EdtSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = EdtSupplier::factory()->create(['code' => 'EDT']);
    }

    public function test_interpreta_la_hoja_por_nombre_de_columna(): void
    {
        $parsed = app(EdtProductImportService::class)->parse($this->sheet());

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(5, $parsed['rows']);

        $gallo = $parsed['rows'][0];
        $this->assertSame(3, $gallo['row']);
        $this->assertSame('28000052', $gallo['code']);
        $this->assertSame('CR GALLO LATA 350ML CT', $gallo['description']);
        $this->assertSame('0.00', $gallo['isv_pct']);
        $this->assertSame(24, $gallo['units_per_box']);
        $this->assertSame('17.6000', $gallo['list_price']);

        // 0.15 del Excel → 15%; 4 decimales se conservan.
        $this->assertSame('15.00', $parsed['rows'][1]['isv_pct']);
        $this->assertSame('8.6957', $parsed['rows'][1]['list_price']);
    }

    public function test_primera_carga_crea_productos_y_los_repetidos_con_otro_precio_van_al_historial(): void
    {
        $service = app(EdtProductImportService::class);
        $plan = $service->plan($service->parse($this->sheet())['rows'], $this->supplier);

        $this->assertSame(
            ['create', 'create', 'create', 'update', 'unchanged'],
            array_column($plan, 'action'),
        );

        $service->apply($plan, 'precios.xlsx');

        $this->assertSame(3, EdtProduct::query()->count());

        $gallo = EdtProduct::query()->where('code', '28000052')->sole();
        $this->assertSame('21.2800', $gallo->list_price, 'Vale la fila de más abajo.');
        $this->assertSame(
            [['17.6000', 'EXCEL PRECIOS.XLSX, FILA 3'], ['21.2800', 'EXCEL PRECIOS.XLSX, FILA 6']],
            $gallo->priceHistory()->orderBy('id')->get()
                ->map(fn (EdtProductPriceHistory $h): array => [$h->list_price, $h->note])
                ->all(),
        );
        $this->assertSame(
            [PriceChangeReason::CargaInicial],
            $gallo->priceHistory()->get()->pluck('reason')->unique()->values()->all(),
        );

        // La fila repetida sin descripción conserva la de arriba.
        $this->assertSame('BC CARNAVAL FRUTS_TRPC EX2 LATA 350ML TR', EdtProduct::query()->where('code', '28000080')->value('description'));
    }

    public function test_volver_a_cargar_el_mismo_archivo_no_cambia_nada(): void
    {
        $service = app(EdtProductImportService::class);
        $service->apply($service->plan($service->parse($this->sheet())['rows'], $this->supplier), 'precios.xlsx');
        $historyBefore = EdtProductPriceHistory::query()->count();

        $plan = $service->plan($service->parse($this->sheet())['rows'], $this->supplier);

        $this->assertSame(['unchanged'], array_values(array_unique(array_column($plan, 'action'))));

        $service->apply($plan, 'precios.xlsx');
        $this->assertSame($historyBefore, EdtProductPriceHistory::query()->count());
    }

    public function test_una_lista_nueva_del_proveedor_actualiza_solo_lo_que_cambio(): void
    {
        $service = app(EdtProductImportService::class);
        $service->apply($service->plan($service->parse($this->sheet())['rows'], $this->supplier), 'precios.xlsx');

        $nueva = $this->sheet();
        $nueva[3][8] = 9.10; // Del Monte melocotón: 8.6957 → 9.10

        $plan = $service->plan($service->parse($nueva)['rows'], $this->supplier);
        $updates = array_values(array_filter($plan, fn (array $e): bool => $e['action'] === 'update'));

        $this->assertCount(1, $updates);
        $this->assertSame('28001298', $updates[0]['code']);
        $this->assertTrue($updates[0]['price_changed']);
    }

    public function test_reporta_filas_con_errores(): void
    {
        $sheet = $this->sheet();
        $sheet[] = [null, 99000001, 'PRODUCTO MALO', 'X', 'X', 'X', 0.12, 24, 10];  // ISV 12%
        $sheet[] = [null, 99000002, 'PRODUCTO MALO', 'X', 'X', 'X', 0.15, 0, 10];   // UxC 0
        $sheet[] = [null, 99000003, 'PRODUCTO MALO', 'X', 'X', 'X', 0.15, 24, null]; // sin precio

        $parsed = app(EdtProductImportService::class)->parse($sheet);

        $this->assertCount(3, $parsed['errors']);
        $this->assertStringContainsString('Fila 8 (código 99000001): ISV', $parsed['errors'][0]);
        $this->assertStringContainsString('Fila 9 (código 99000002): unidades por caja', $parsed['errors'][1]);
        $this->assertStringContainsString('Fila 10 (código 99000003): precio de lista', $parsed['errors'][2]);
    }

    public function test_rechaza_formulas_decimales_de_mas_y_textos_largos(): void
    {
        $sheet = $this->sheet();
        $sheet[] = [null, 99000001, '=UPPER("gallo")', 'X', 'X', 'X', 0.15, 24, 10];   // fórmula
        $sheet[] = [null, 99000002, 'PRODUCTO', 'X', 'X', 'X', 0.15, 24, '=F2/1.15'];   // fórmula en precio
        $sheet[] = [null, 99000003, 'PRODUCTO', 'X', 'X', 'X', 0.15, 24, 8.69565217];   // 8 decimales
        $sheet[] = [null, 99000004, 'PRODUCTO', 'X', 'X', 'X', 0.15, 24, 0.00004];      // se volvería 0
        $sheet[] = [null, 99000005, str_repeat('A', 201), 'X', 'X', 'X', 0.15, 24, 10]; // descripción larga
        $sheet[] = [null, 99000006, 'PRODUCTO', 'X', 'X', 'X', 0.15, 24, 17.60000];     // 17.6: válido

        $parsed = app(EdtProductImportService::class)->parse($sheet);

        $this->assertCount(5, $parsed['errors']);
        $this->assertStringContainsString('"Descripción" tiene una fórmula', $parsed['errors'][0]);
        $this->assertStringContainsString('"PVD - ISV" tiene una fórmula', $parsed['errors'][1]);
        $this->assertStringContainsString('más de 4 decimales', $parsed['errors'][2]);
        $this->assertStringContainsString('más de 4 decimales', $parsed['errors'][3]);
        $this->assertStringContainsString('pasa de 200 caracteres', $parsed['errors'][4]);
        $this->assertSame('17.6000', collect($parsed['rows'])->firstWhere('code', '99000006')['list_price']);
    }

    public function test_si_alguien_cambia_un_producto_despues_del_plan_no_lo_pisa(): void
    {
        $service = app(EdtProductImportService::class);
        $service->apply($service->plan($service->parse($this->sheet())['rows'], $this->supplier), 'precios.xlsx');

        $nueva = $this->sheet();
        $nueva[3][8] = 9.10;
        $plan = $service->plan($service->parse($nueva)['rows'], $this->supplier);

        // Mientras la consola espera "¿Aplicar?", alguien cambia el precio en pantalla.
        EdtProduct::query()->where('code', '28001298')->sole()->update(['list_price' => '9.50']);

        try {
            $service->apply($plan, 'precios.xlsx');
            $this->fail('Debía cancelar la carga.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('28001298 cambió mientras se confirmaba', $e->getMessage());
        }

        $this->assertSame('9.5000', EdtProduct::query()->where('code', '28001298')->value('list_price'));
    }

    public function test_un_codigo_de_otro_proveedor_es_error(): void
    {
        EdtProduct::factory()->create(['code' => '28000052']); // otro proveedor

        $service = app(EdtProductImportService::class);
        $plan = $service->plan($service->parse($this->sheet())['rows'], $this->supplier);

        $errors = array_values(array_filter($plan, fn (array $e): bool => $e['action'] === 'error'));
        $this->assertCount(1, $errors, 'Un error por código (en su última fila), no uno por cada repetición.');
        $this->assertSame('28000052', $errors[0]['code']);
        $this->assertSame('el código ya existe con otro proveedor', $errors[0]['error']);
    }

    // ── Comando ─────────────────────────────────────────────────────

    public function test_el_comando_en_modo_prueba_no_guarda_nada(): void
    {
        $path = $this->xlsx($this->sheet());

        $this->artisan('edt:importar-productos', ['archivo' => $path, '--dry-run' => true])
            ->expectsOutputToContain('MODO PRUEBA')
            ->assertSuccessful();

        $this->assertSame(0, EdtProduct::query()->count());
    }

    public function test_el_comando_carga_el_excel(): void
    {
        $path = $this->xlsx($this->sheet());

        $this->artisan('edt:importar-productos', ['archivo' => $path, '--force' => true])
            ->expectsOutputToContain('catálogo actualizado')
            ->assertSuccessful();

        $this->assertSame(3, EdtProduct::query()->count());
        $this->assertSame(4, EdtProductPriceHistory::query()->count());

        // Segunda vez: nada que hacer.
        $this->artisan('edt:importar-productos', ['archivo' => $path, '--force' => true])
            ->expectsOutputToContain('ya está al día')
            ->assertSuccessful();
    }

    public function test_el_comando_pide_confirmacion_y_se_puede_cancelar(): void
    {
        $path = $this->xlsx($this->sheet());

        $this->artisan('edt:importar-productos', ['archivo' => $path])
            ->expectsConfirmation('¿Aplicar estos cambios?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, EdtProduct::query()->count());
    }

    public function test_el_comando_falla_sin_guardar_si_hay_errores(): void
    {
        $sheet = $this->sheet();
        $sheet[] = [null, 99000001, 'PRODUCTO MALO', 'X', 'X', 'X', 0.12, 24, 10];

        $this->artisan('edt:importar-productos', ['archivo' => $this->xlsx($sheet), '--force' => true])
            ->assertFailed();

        $this->assertSame(0, EdtProduct::query()->count());
    }

    public function test_el_comando_falla_si_no_existe_el_proveedor_o_la_hoja(): void
    {
        $path = $this->xlsx($this->sheet());

        $this->artisan('edt:importar-productos', ['archivo' => $path, '--proveedor' => 'NOEXISTE'])
            ->expectsOutputToContain('No existe el proveedor')
            ->assertFailed();

        $this->artisan('edt:importar-productos', ['archivo' => $path, '--hoja' => 'Otra hoja'])
            ->expectsOutputToContain('no tiene una hoja')
            ->assertFailed();
    }

    /**
     * Hoja con la forma de "Pedido EDTH" (columna A vacía, encabezados en
     * la fila 2). Columnas: B código, C descripción, D categoría,
     * E familia, F presentación, G ISV, H UxC, I PVD - ISV, J PVD + ISV.
     *
     * @return list<array<int, mixed>>
     */
    private function sheet(): array
    {
        return [
            [null, null, null, null, null, null, null, null, null, 0.03],
            [null, 'Cod. Articulo', 'Descripción', 'Categoria', 'Familia', 'Presentacion', 'ISV', 'UxC', 'PVD - ISV', 'PVD + ISV'],
            [null, 28000052, 'CR GALLO LATA 350ML CT', 'Cerveza', 'Cerveza', 'Lata 12 onz', 0, 24, 17.6, 17.6],
            [null, 28001298, 'NC DEL_MONTE MELOCOTON LATA 330ML TR', 'Del Monte', 'Nect. Del Monte', 'Lata', 0.15, 24, 8.6957, 10],
            [null, 28000080, 'BC CARNAVAL FRUTS_TRPC EX2 LATA 350ML TR', 'Refrescos', 'Carnaval', 'Lata', 0, 24, 12.09, 12.09],
            // Repetido más abajo con precio nuevo (como la fila 176 del Excel real).
            [null, 28000052, 'CR GALLO LATA 350ML CT', 'Cerveza', 'Cerveza', 'Lata 12 onz', 0, 24, 21.28, 21.28],
            // Repetido sin descripción y sin cambios (como la fila 164).
            [null, 28000080, null, 'Refrescos', 'Carnaval', 'Lata', 0, 24, 12.09, 12.09],
        ];
    }

    /**
     * Escribe la hoja a un .xlsx real con Maatwebsite (sin guardar fixtures
     * binarios en el repo).
     *
     * @param  list<array<int, mixed>>  $rows
     */
    private function xlsx(array $rows): string
    {
        Storage::fake('local');

        // WithStrictNullComparison: sin esto Maatwebsite escribe el ISV 0 como
        // celda vacía (0 == null), y el Excel del cliente sí trae el 0.
        Excel::store(new class($rows) implements FromArray, WithStrictNullComparison, WithTitle
        {
            /** @param list<array<int, mixed>> $rows */
            public function __construct(private readonly array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function title(): string
            {
                return 'Pedido EDTH';
            }
        }, 'edt/precios.xlsx', 'local');

        return Storage::disk('local')->path('edt/precios.xlsx');
    }
}
