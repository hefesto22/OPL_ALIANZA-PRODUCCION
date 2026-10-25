<?php

namespace Tests\Feature\Edt;

use App\Enums\Edt\EdtOrderStatus;
use App\Enums\Edt\EdtPaymentType;
use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtClient;
use App\Models\Edt\EdtOrder;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtSeller;
use App\Models\Edt\EdtSupplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Edt\EdtOrderService;
use App\Services\Edt\EdtProductPriceService;
use App\Services\Edt\EdtRouteAssignmentService;
use App\Services\Edt\EdtRuleException;
use Database\Seeders\EdtPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Preventas del EDT: precios por escala, sueltas, ISV, envío con precio del
 * día, correcciones del vendedor y revisión de la bodega.
 *
 * Productos de prueba (escalas por defecto: 25 cajas 3%, 50 cajas 6%):
 *   GALLO   lista 17.60 · 24 u. · exento → caja 422.40, MAYORISTA 1 410.00
 *   GRAVADO lista 20.00 · 12 u. · 15%    → caja 276.00, unidad 23.00
 */
class EdtOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private EdtOrderService $orders;

    private Warehouse $oac;

    private EdtSeller $juan;

    private EdtClient $cliente;

    private EdtProduct $gallo;

    private EdtProduct $gravado;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 09:00:00');

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);

        $this->orders = app(EdtOrderService::class);
        $this->oac = Warehouse::factory()->oac()->create();
        $this->juan = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);

        $ruta = EdtRoute::factory()->covering(['0401'])->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($ruta, $this->juan, Carbon::parse('2026-10-01'));

        $this->cliente = EdtClient::factory()->inMunicipality('0401')->create(['warehouse_id' => $this->oac->id]);

        $supplier = EdtSupplier::factory()->create();
        $this->gallo = EdtProduct::factory()->for($supplier, 'supplier')->create([
            'code' => '28000052', 'list_price' => '17.60', 'units_per_box' => 24, 'isv_pct' => 0,
        ]);
        $this->gravado = EdtProduct::factory()->for($supplier, 'supplier')->create([
            'code' => '29000231', 'list_price' => '20.00', 'units_per_box' => 12, 'isv_pct' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Vendedor ────────────────────────────────────────────────────

    public function test_cajas_y_sueltas_a_precio_de_detalle(): void
    {
        $order = $this->draft();
        $order = $this->orders->setQuantity($order, $this->gallo, 2, 5);

        // 2 × 422.40 + 5 × 17.60
        $this->assertSame('932.80', $order->total);
        $this->assertSame('932.80', $order->exempt_total);
        $this->assertSame(1, $order->lines_count);

        $line = $order->lines()->first();
        $this->assertNull($line->tier_name);
        // Costo: 2 × 359.04 + 5 × 14.96
        $this->assertSame('792.88', $line->cost_total);
    }

    public function test_la_escala_mayorista_cuenta_las_cajas_del_mismo_codigo(): void
    {
        $order = $this->orders->setQuantity($this->draft(), $this->gallo, 25, 0);

        $line = $order->lines()->first();
        $this->assertSame('MAYORISTA 1', $line->tier_name);
        $this->assertSame('410.00', $line->box_price);
        $this->assertSame('10250.00', $order->total);

        // 24 cajas de otro producto no suben a nadie a mayorista.
        $order = $this->orders->setQuantity($order, $this->gravado, 24, 0);
        $this->assertNull($order->lines()->where('product_id', $this->gravado->id)->value('tier_name'));
    }

    public function test_las_sueltas_que_completan_una_caja_pasan_a_cajas(): void
    {
        $order = $this->orders->setQuantity($this->draft(), $this->gallo, 0, 30);

        $line = $order->lines()->first();
        $this->assertSame([1, 6], [$line->boxes, $line->units]);
    }

    public function test_separa_gravado_e_isv(): void
    {
        $order = $this->orders->setQuantity($this->draft(), $this->gravado, 2, 0);
        $order = $this->orders->setQuantity($order, $this->gallo, 1, 0);

        // 2 × 276.00 = 552.00 → base 480.00 + ISV 72.00
        $this->assertSame('480.00', $order->taxable_15);
        $this->assertSame('72.00', $order->isv_15);
        $this->assertSame('422.40', $order->exempt_total);
        $this->assertSame('974.40', $order->total);
    }

    public function test_cero_quita_el_producto(): void
    {
        $order = $this->orders->setQuantity($this->draft(), $this->gallo, 2, 0);
        $order = $this->orders->setQuantity($order, $this->gallo, 0, 0);

        $this->assertSame(0, $order->lines()->count());
        $this->assertSame('0.00', $order->total);
    }

    public function test_un_solo_borrador_por_cliente(): void
    {
        $this->assertSame($this->draft()->id, $this->draft()->id);
    }

    public function test_no_puede_tomar_pedido_a_un_cliente_fuera_de_sus_zonas(): void
    {
        $ajeno = EdtClient::factory()->inMunicipality('0405')->create(['warehouse_id' => $this->oac->id]);

        $this->expectException(EdtRuleException::class);
        $this->orders->draftFor($this->juan, $ajeno);
    }

    public function test_credito_solo_si_el_cliente_lo_tiene(): void
    {
        $order = $this->draft();

        try {
            $this->orders->updateHeader($order, EdtPaymentType::Credito, null);
            $this->fail('Debió rechazar el crédito.');
        } catch (EdtRuleException $e) {
            $this->assertSame('Este cliente no tiene crédito.', $e->getMessage());
        }

        $this->cliente->update(['credit_enabled' => true, 'credit_limit' => 5000, 'credit_days' => 15]);
        $order = $this->orders->updateHeader($order, EdtPaymentType::Credito, 'entregar en la mañana');

        $this->assertSame(EdtPaymentType::Credito, $order->payment_type);
        $this->assertSame('ENTREGAR EN LA MAÑANA', $order->note);
    }

    public function test_enviar_da_numero_y_no_se_puede_enviar_vacio(): void
    {
        try {
            $this->orders->submit($this->draft());
            $this->fail('No debió enviar un pedido vacío.');
        } catch (EdtRuleException $e) {
            $this->assertStringContainsString('al menos un producto', $e->getMessage());
        }

        $first = $this->orders->submit($this->orders->setQuantity($this->draft(), $this->gallo, 1, 0));
        $this->assertMatchesRegularExpression('/^PED-\d{6}$/', $first->number);
        $this->assertSame(EdtOrderStatus::Enviado, $first->status);

        // La secuencia no se revierte con la transacción del test: se
        // compara contra el anterior, no contra un número fijo.
        $second = $this->orders->submit($this->orders->setQuantity($this->draft(), $this->gallo, 1, 0));
        $this->assertSame((int) substr($first->number, 4) + 1, (int) substr($second->number, 4));
    }

    public function test_se_cobra_el_precio_del_dia_de_la_preventa(): void
    {
        $order = $this->orders->setQuantity($this->draft(), $this->gallo, 1, 0);

        // Cambia el precio mientras es borrador: al enviar toma el del día.
        $this->changePrice($this->gallo, '18.00');
        $order = $this->orders->submit($order);
        $this->assertSame('432.00', $order->total);

        // Cambia otra vez después de enviar: la preventa no se mueve,
        // aunque el vendedor la corrija.
        $this->changePrice($this->gallo, '25.00');
        $order = $this->orders->setQuantity($order, $this->gallo, 2, 0);
        $this->assertSame('864.00', $order->total);
        $this->assertSame('18.0000', $order->lines()->value('list_price'));
    }

    public function test_el_vendedor_anula_hasta_que_la_bodega_la_toca(): void
    {
        // El borrador se descarta (se borra).
        $this->assertNull($this->orders->cancel($this->orders->setQuantity($this->draft(), $this->gallo, 1, 0)));
        $this->assertSame(0, EdtOrder::query()->count());

        $sent = $this->sent(['gallo' => 2]);
        $cancelled = $this->orders->cancel($sent, 'cliente cerrado');
        $this->assertSame(EdtOrderStatus::Anulado, $cancelled->status);
        $this->assertSame('CLIENTE CERRADO', $cancelled->close_reason);

        // Anular dos veces no hace nada.
        $this->assertSame(EdtOrderStatus::Anulado, $this->orders->cancel($cancelled)->status);
    }

    // ── Bodega ──────────────────────────────────────────────────────

    public function test_la_bodega_baja_lo_que_no_hay_y_se_recalcula_la_escala(): void
    {
        $order = $this->sent(['gallo' => 30]);
        $this->assertSame('12300.00', $order->total); // 30 × 410.00 (MAYORISTA 1)

        $line = $order->lines()->first();
        $order = $this->orders->adjustLine($line, 20, 0);

        // 20 cajas ya no llegan a mayorista: 20 × 422.40.
        $this->assertSame('8448.00', $order->total);
        $this->assertSame('12300.00', $order->requested_total);
        $this->assertSame(EdtOrderStatus::EnRevision, $order->status);

        $line->refresh();
        $this->assertSame([30, 20], [$line->requested_boxes, $line->boxes]);
        $this->assertTrue($line->isReduced());
    }

    public function test_la_bodega_no_puede_dar_mas_de_lo_pedido(): void
    {
        $order = $this->sent(['gallo' => 2]);

        $this->expectException(EdtRuleException::class);
        $this->orders->adjustLine($order->lines()->first(), 2, 1);
    }

    public function test_cuando_la_bodega_la_toca_el_vendedor_ya_no_la_cambia(): void
    {
        $order = $this->sent(['gallo' => 2, 'gravado' => 1]);
        $this->orders->adjustLine($order->lines()->first(), 1, 0);

        foreach ([
            fn () => $this->orders->setQuantity($order, $this->gallo, 5, 0),
            fn () => $this->orders->cancel($order),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('El vendedor no debió poder cambiarla.');
            } catch (EdtRuleException $e) {
                $this->assertStringContainsString('ya está revisando', $e->getMessage());
            }
        }
    }

    public function test_aprobar_y_rechazar(): void
    {
        $reviewer = User::factory()->create();
        $this->actingAs($reviewer);

        $order = $this->orders->approve($this->sent(['gallo' => 2]));
        $this->assertSame(EdtOrderStatus::Aprobado, $order->status);
        $this->assertSame($reviewer->id, $order->reviewed_by);

        // Ya aprobada no se vuelve a revisar.
        $this->expectExceptionMessage('APROBADO');
        $this->orders->reject($order, 'otra cosa');
    }

    public function test_sin_productos_no_se_aprueba_y_el_rechazo_pide_motivo(): void
    {
        $order = $this->sent(['gallo' => 2]);
        $order = $this->orders->adjustLine($order->lines()->first(), 0, 0);

        try {
            $this->orders->approve($order);
            $this->fail('No debió aprobar una preventa sin productos.');
        } catch (EdtRuleException $e) {
            $this->assertStringContainsString('recházala', $e->getMessage());
        }

        try {
            $this->orders->reject($order, '  ');
            $this->fail('El rechazo pide motivo.');
        } catch (EdtRuleException $e) {
            $this->assertSame('Escribe el motivo del rechazo.', $e->getMessage());
        }

        $order = $this->orders->reject($order, 'no hay existencia');
        $this->assertSame(EdtOrderStatus::Rechazado, $order->status);
        $this->assertSame('NO HAY EXISTENCIA', $order->close_reason);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function draft(): EdtOrder
    {
        return $this->orders->draftFor($this->juan, $this->cliente);
    }

    /**
     * @param  array<string, int>  $boxes  ['gallo' => 2, 'gravado' => 1]
     */
    private function sent(array $boxes): EdtOrder
    {
        $order = $this->draft();
        foreach ($boxes as $product => $count) {
            $order = $this->orders->setQuantity($order, $this->{$product}, $count, 0);
        }

        return $this->orders->submit($order);
    }

    private function changePrice(EdtProduct $product, string $listPrice): void
    {
        $product->refresh();

        app(EdtProductPriceService::class)->changePrice(
            product: $product,
            listPrice: $listPrice,
            boxCost: $product->priceQuote()->boxCost,
            unitsPerBox: (int) $product->units_per_box,
            isvPct: (string) $product->isv_pct,
            reason: PriceChangeReason::ListaProveedor,
        );
    }
}
