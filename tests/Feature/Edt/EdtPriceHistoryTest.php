<?php

namespace Tests\Feature\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtPriceTier;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtProductPriceHistory;
use App\Models\Edt\EdtSupplier;
use App\Models\User;
use App\Services\Edt\EdtPriceHistoryService;
use App\Services\Edt\EdtPricingService;
use App\Services\Edt\EdtProductPriceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Historial de precios del EDT: cuándo se escribe, qué guarda y que no se
 * pueda alterar.
 *
 * Regla de Mauricio (2026-10-03): un código = un producto, y cada vez que
 * algo cambie el precio queda en el historial. Lo escriben los observers
 * dentro de la misma transacción del cambio.
 */
class EdtPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private EdtSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // La migración ya sembró MAYORISTA 1 (25 cajas, 3%) y MAYORISTA 2 (50, 6%).
        $this->supplier = EdtSupplier::factory()->create(['code' => 'EDT', 'operation_discount_pct' => 15]);
    }

    // ── Creación ────────────────────────────────────────────────────

    public function test_crear_un_producto_escribe_la_primera_fila_con_la_foto_de_precios(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $gallo = $this->gallo();

        $history = $gallo->priceHistory()->sole();

        $this->assertSame(PriceChangeReason::Creacion, $history->reason);
        $this->assertSame('17.6000', $history->list_price);
        $this->assertSame('15.00', $history->operation_discount_pct);
        $this->assertSame('14.9600', $history->unit_cost);
        $this->assertSame('17.60', $history->retail_unit_price);
        $this->assertSame('422.40', $history->retail_box_price);
        $this->assertSame(
            ['MAYORISTA 1' => '410.00', 'MAYORISTA 2' => '398.00'],
            array_column($history->wholesale_prices, 'box_price', 'name'),
        );
        $this->assertSame($user->id, $history->created_by);
    }

    // ── Cambios del producto ────────────────────────────────────────

    public function test_cambiar_la_descripcion_no_escribe_historial(): void
    {
        $gallo = $this->gallo();

        $gallo->update(['description' => 'CR GALLO LATA 350ML CT NUEVA', 'category' => 'CERVEZAS', 'is_active' => false]);

        $this->assertSame(1, $gallo->priceHistory()->count());
    }

    public function test_cambiar_un_dato_que_mueve_el_precio_fuera_de_la_accion_queda_como_edicion(): void
    {
        $gallo = $this->gallo();

        $gallo->update(['units_per_box' => 12]);

        $last = $this->lastHistory($gallo);
        $this->assertSame(PriceChangeReason::Edicion, $last->reason);
        $this->assertSame(12, $last->units_per_box);
        $this->assertSame('211.20', $last->retail_box_price);
    }

    public function test_cambiar_precio_con_el_servicio_guarda_motivo_y_nota_en_mayusculas(): void
    {
        $gallo = $this->gallo();

        $changed = app(EdtProductPriceService::class)->changePrice(
            $gallo, '21.28', 24, '0', PriceChangeReason::FacturaCompra, 'factura 001-001-01-00012345',
        );

        $this->assertTrue($changed);
        $this->assertSame('21.2800', $gallo->list_price, 'La instancia de quien llamó queda al día.');

        $last = $this->lastHistory($gallo);
        $this->assertSame(PriceChangeReason::FacturaCompra, $last->reason);
        $this->assertSame('FACTURA 001-001-01-00012345', $last->note);
        $this->assertSame('21.28', $last->retail_unit_price);
        $this->assertSame(
            ['MAYORISTA 1' => '496.00', 'MAYORISTA 2' => '481.00'],
            array_column($last->wholesale_prices, 'box_price', 'name'),
        );
    }

    public function test_cambiar_precio_con_los_mismos_datos_no_ensucia_el_historial(): void
    {
        $gallo = $this->gallo();

        $changed = app(EdtProductPriceService::class)->changePrice(
            $gallo, '17.60', 24, '0.00', PriceChangeReason::ListaProveedor,
        );

        $this->assertFalse($changed);
        $this->assertSame(1, $gallo->priceHistory()->count());
    }

    public function test_cambiar_precio_bloquea_la_fila_del_producto(): void
    {
        $gallo = $this->gallo();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(EdtProductPriceService::class)->changePrice($gallo, '21.28', 24, '0', PriceChangeReason::ListaProveedor);

        $this->assertNotEmpty(
            array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "edt_products"') && str_contains($sql, 'for update')),
            'Dos cambios de precio simultáneos deben quedar en orden: SELECT … FOR UPDATE.'
        );
    }

    public function test_el_historial_pone_en_fila_los_cambios_de_precio_simultaneos(): void
    {
        $gallo = $this->gallo();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $gallo->update(['list_price' => '21.28']);
        $this->supplier->update(['operation_discount_pct' => 12]);

        $this->assertGreaterThanOrEqual(
            2,
            count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'))),
            'Cada escritura de historial toma el advisory lock del EDT.'
        );
    }

    public function test_el_historial_usa_el_descuento_confirmado_aunque_el_producto_traiga_el_viejo(): void
    {
        $gallo = $this->gallo()->load('supplier');
        $this->assertSame('15.00', $gallo->supplier->operation_discount_pct);

        // Otro usuario cambió el descuento y ya confirmó (simulado sin observer).
        DB::table('edt_suppliers')->where('id', $this->supplier->id)->update(['operation_discount_pct' => 10]);

        $gallo->update(['list_price' => '20.00']);

        $last = $this->lastHistory($gallo);
        $this->assertSame('10.00', $last->operation_discount_pct);
        $this->assertSame('18.0000', $last->unit_cost); // 20 × 0.90
    }

    // ── Cambios del proveedor ───────────────────────────────────────

    public function test_cambiar_el_descuento_del_proveedor_escribe_una_fila_por_cada_producto_suyo(): void
    {
        $gallo = $this->gallo();
        $incaparina = EdtProduct::factory()->for($this->supplier, 'supplier')->create(['list_price' => '29.20', 'units_per_box' => 50]);
        $otro = EdtProduct::factory()->create(); // de OTRO proveedor

        $this->supplier->update(['operation_discount_pct' => 12.5]);

        foreach ([$gallo, $incaparina] as $product) {
            $last = $this->lastHistory($product);
            $this->assertSame(PriceChangeReason::DescuentoProveedor, $last->reason);
            $this->assertSame('12.50', $last->operation_discount_pct);
            $this->assertSame('DESCUENTO DE 15.00% A 12.50%', $last->note);
        }

        // Costo nuevo del Gallo: 17.60 × 0.875 = 15.40. El detalle no cambia.
        $this->assertSame('15.4000', $this->lastHistory($gallo)->unit_cost);
        $this->assertSame('17.60', $this->lastHistory($gallo)->retail_unit_price);

        $this->assertSame(1, $otro->priceHistory()->count(), 'Los productos de otro proveedor no se tocan.');
    }

    public function test_cambiar_otros_datos_del_proveedor_no_escribe_historial(): void
    {
        $gallo = $this->gallo();

        $this->supplier->update(['name' => 'EDT HONDURAS S.A.', 'phone' => '9999-0000']);

        $this->assertSame(1, $gallo->priceHistory()->count());
    }

    // ── Cambios de escalas ──────────────────────────────────────────

    public function test_cambiar_una_escala_escribe_una_fila_por_producto_de_todo_el_catalogo(): void
    {
        $gallo = $this->gallo();
        $otro = EdtProduct::factory()->create();

        EdtPriceTier::query()->where('name', 'MAYORISTA 1')->sole()->update(['discount_pct' => 2]);

        foreach ([$gallo, $otro] as $product) {
            $this->assertSame(PriceChangeReason::Escalas, $this->lastHistory($product)->reason);
        }

        // 17.60 × 24 × 0.98 = 413.95 → 414 (hacia arriba).
        $this->assertSame('414.00', $this->lastHistory($gallo)->wholesale_prices[0]['box_price']);
    }

    public function test_renombrar_una_escala_no_escribe_historial(): void
    {
        $gallo = $this->gallo();

        EdtPriceTier::query()->where('name', 'MAYORISTA 1')->sole()->update(['name' => 'Mayorista uno']);

        $this->assertSame(1, $gallo->priceHistory()->count());
    }

    public function test_desactivar_una_escala_la_saca_de_los_precios(): void
    {
        $gallo = $this->gallo();

        EdtPriceTier::query()->where('name', 'MAYORISTA 2')->sole()->update(['is_active' => false]);

        $this->assertSame(['MAYORISTA 1'], array_column($this->lastHistory($gallo)->wholesale_prices, 'name'));
        $this->assertCount(1, $gallo->fresh()->priceQuote()->wholesale);
    }

    public function test_crear_y_borrar_una_escala_escribe_historial(): void
    {
        $gallo = $this->gallo();

        $nueva = EdtPriceTier::factory()->create(['name' => 'MAYORISTA 3', 'min_boxes' => 100, 'discount_pct' => 8]);
        $this->assertSame(
            ['MAYORISTA 1', 'MAYORISTA 2', 'MAYORISTA 3'],
            array_column($this->lastHistory($gallo)->wholesale_prices, 'name'),
        );

        $nueva->delete();
        $this->assertSame(
            ['MAYORISTA 1', 'MAYORISTA 2'],
            array_column($this->lastHistory($gallo)->wholesale_prices, 'name'),
        );
        $this->assertSame(3, $gallo->priceHistory()->count());
    }

    public function test_las_escalas_inactivas_o_renombradas_no_escriben_historial(): void
    {
        $gallo = $this->gallo();

        $inactiva = EdtPriceTier::factory()->create(['min_boxes' => 200, 'is_active' => false]);
        $inactiva->update(['discount_pct' => 9]);
        $inactiva->delete();

        $nueva = EdtPriceTier::factory()->create(['name' => 'MAYORISTA 3', 'min_boxes' => 100]);
        $this->assertSame(2, $gallo->priceHistory()->count(), 'Crear una escala activa sí escribe.');

        // La misma instancia recién creada: renombrarla no es "nueva escala" otra vez.
        $nueva->update(['name' => 'MAYORISTA TRES']);
        $this->assertSame(2, $gallo->priceHistory()->count());
    }

    public function test_activar_una_escala_inactiva_si_escribe_historial(): void
    {
        $gallo = $this->gallo();
        $inactiva = EdtPriceTier::factory()->create(['min_boxes' => 200, 'is_active' => false]);

        $inactiva->update(['is_active' => true]);

        $this->assertSame(2, $gallo->priceHistory()->count());
        $this->assertContains($inactiva->name, array_column($this->lastHistory($gallo)->wholesale_prices, 'name'));
    }

    // ── El historial no se altera ───────────────────────────────────

    public function test_el_historial_no_se_puede_editar(): void
    {
        $history = $this->gallo()->priceHistory()->sole();

        $this->expectException(LogicException::class);
        $history->update(['list_price' => '1.00']);
    }

    public function test_el_historial_no_se_puede_borrar(): void
    {
        $history = $this->gallo()->priceHistory()->sole();

        $this->expectException(LogicException::class);
        $history->delete();
    }

    public function test_un_producto_con_historial_no_se_puede_borrar_en_la_bd(): void
    {
        $gallo = $this->gallo();

        $this->expectException(QueryException::class);
        DB::table('edt_products')->where('id', $gallo->id)->delete();
    }

    public function test_un_proveedor_con_productos_no_se_puede_borrar_en_la_bd(): void
    {
        $this->gallo();

        $this->expectException(QueryException::class);
        DB::table('edt_suppliers')->where('id', $this->supplier->id)->delete();
    }

    // ── Atomicidad ──────────────────────────────────────────────────

    public function test_si_el_historial_falla_el_cambio_de_precio_se_revierte(): void
    {
        $gallo = $this->gallo();

        $this->app->instance(EdtPriceHistoryService::class, new class(app(EdtPricingService::class)) extends EdtPriceHistoryService
        {
            public function record(EdtProduct $product, PriceChangeReason $reason, ?string $note = null): EdtProductPriceHistory
            {
                throw new RuntimeException('falla simulada del historial');
            }

            public function recordForProducts(Builder|Relation $products, PriceChangeReason $reason, ?string $note = null): int
            {
                throw new RuntimeException('falla simulada del historial');
            }
        });

        try {
            $gallo->update(['list_price' => '99.00']);
            $this->fail('Se esperaba la excepción del historial.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame('17.6000', EdtProduct::query()->whereKey($gallo->id)->value('list_price'));

        try {
            $this->supplier->update(['operation_discount_pct' => 10]);
            $this->fail('Se esperaba la excepción del historial.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame('15.00', EdtSupplier::query()->whereKey($this->supplier->id)->value('operation_discount_pct'));
    }

    // ── Datos ───────────────────────────────────────────────────────

    public function test_el_texto_del_producto_se_guarda_en_mayusculas(): void
    {
        $product = EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'code' => ' 27000207 ',
            'description' => 'Granola almendra Gran Día 380g',
            'category' => 'alimentos',
            'family' => 'cereales',
            'presentation' => 'gran día',
        ])->fresh();

        $this->assertSame('27000207', $product->code);
        $this->assertSame('GRANOLA ALMENDRA GRAN DÍA 380G', $product->description);
        $this->assertSame('ALIMENTOS', $product->category);
        $this->assertSame('GRAN DÍA', $product->presentation);
    }

    public function test_la_bd_rechaza_un_isv_que_no_es_0_15_ni_18(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('edt_products_isv_valid');

        EdtProduct::factory()->for($this->supplier, 'supplier')->create(['isv_pct' => 12]);
    }

    public function test_la_bd_rechaza_cero_unidades_por_caja(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('edt_products_units_per_box_positive');

        EdtProduct::factory()->for($this->supplier, 'supplier')->create(['units_per_box' => 0]);
    }

    public function test_la_bd_rechaza_precio_de_lista_en_cero(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('edt_products_list_price_positive');

        EdtProduct::factory()->for($this->supplier, 'supplier')->create(['list_price' => 0]);
    }

    private function gallo(): EdtProduct
    {
        return EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'code' => '28000052',
            'description' => 'CR GALLO LATA 350ML CT',
            'units_per_box' => 24,
            'isv_pct' => 0,
            'list_price' => '17.60',
        ]);
    }

    private function lastHistory(EdtProduct $product): EdtProductPriceHistory
    {
        return $product->priceHistory()->latest('id')->firstOrFail();
    }
}
