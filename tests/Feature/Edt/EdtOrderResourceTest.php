<?php

namespace Tests\Feature\Edt;

use App\Enums\Edt\EdtOrderStatus;
use App\Filament\Resources\Edt\Orders\EdtOrderResource;
use App\Filament\Resources\Edt\Orders\Pages\ListEdtOrders;
use App\Filament\Resources\Edt\Orders\Pages\PickEdtOrderClient;
use App\Filament\Resources\Edt\Orders\Pages\TakeEdtOrder;
use App\Filament\Resources\Edt\Orders\Pages\ViewEdtOrder;
use App\Filament\Resources\Edt\Orders\RelationManagers\LinesRelationManager;
use App\Models\Edt\EdtClient;
use App\Models\Edt\EdtOrder;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtSeller;
use App\Models\Edt\EdtSupplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Edt\EdtOrderService;
use App\Services\Edt\EdtRouteAssignmentService;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantallas de preventas: el vendedor elige cliente, arma y envía desde el
 * celular; la bodega revisa (baja lo que no hay), aprueba o rechaza. Cada
 * quien ve solo lo suyo.
 */
class EdtOrderResourceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oac;

    private Warehouse $oas;

    private EdtSeller $juan;

    private EdtClient $hoy;

    private EdtClient $otroDia;

    private EdtProduct $gallo;

    private User $encargadoOac;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 09:00:00'); // martes

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        $this->juan = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);

        // Martes: Santa Rosa. Jueves: Dolores.
        $martes = EdtRoute::factory()->covering(['0401'])->visitingOn([2])->create(['warehouse_id' => $this->oac->id]);
        $jueves = EdtRoute::factory()->covering(['0407'])->visitingOn([4])->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($martes, $this->juan, Carbon::parse('2026-10-01'));
        app(EdtRouteAssignmentService::class)->assign($jueves, $this->juan, Carbon::parse('2026-10-01'));

        $this->otroDia = EdtClient::factory()->inMunicipality('0407')->create(['warehouse_id' => $this->oac->id, 'name' => 'ABARROTERIA ALFA']);
        $this->hoy = EdtClient::factory()->inMunicipality('0401')->create(['warehouse_id' => $this->oac->id, 'name' => 'PULPERIA ZETA']);

        $this->gallo = EdtProduct::factory()->for(EdtSupplier::factory(), 'supplier')->create([
            'code' => '28000052', 'description' => 'CR GALLO LATA 350ML CT',
            'list_price' => '17.60', 'units_per_box' => 24, 'isv_pct' => 0,
        ]);

        $this->encargadoOac = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $this->encargadoOac->assignRole('encargado');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Vendedor ────────────────────────────────────────────────────

    public function test_el_vendedor_elige_cliente_con_los_de_hoy_primero(): void
    {
        $this->actingAs($this->juan->user);

        Livewire::test(ListEdtOrders::class)->assertActionVisible('newOrder');

        Livewire::test(PickEdtOrderClient::class)
            ->assertSeeInOrder(['PULPERIA ZETA', 'HOY', 'ABARROTERIA ALFA'])
            ->set('search', 'alfa')
            ->assertSee('ABARROTERIA ALFA')
            ->assertDontSee('PULPERIA ZETA')
            ->call('start', $this->otroDia->id)
            ->assertRedirect(EdtOrderResource::getUrl('take', ['record' => EdtOrder::query()->sole()]));

        $this->assertSame(EdtOrderStatus::Borrador, EdtOrder::query()->sole()->status);
    }

    public function test_el_vendedor_arma_y_envia_la_preventa(): void
    {
        $this->actingAs($this->juan->user);
        $order = app(EdtOrderService::class)->draftFor($this->juan, $this->hoy);

        Livewire::test(TakeEdtOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('CR GALLO LATA 350ML CT')
            ->call('step', $this->gallo->id, 'boxes', 1)
            ->call('step', $this->gallo->id, 'boxes', 1)
            ->call('setQuantity', $this->gallo->id, 2, 5)
            ->assertSee('L 932.80')
            ->callAction('submit')
            ->assertRedirect(EdtOrderResource::getUrl('index'));

        $order->refresh();
        $this->assertSame(EdtOrderStatus::Enviado, $order->status);
        $this->assertSame('932.80', $order->total);
    }

    public function test_cuando_la_bodega_la_toca_el_vendedor_va_a_verla(): void
    {
        $order = $this->sentOrder(3);
        app(EdtOrderService::class)->adjustLine($order->lines()->first(), 2, 0);

        $this->actingAs($this->juan->user);

        Livewire::test(TakeEdtOrder::class, ['record' => $order->getRouteKey()])
            ->assertRedirect(EdtOrderResource::getUrl('view', ['record' => $order]));
    }

    public function test_el_vendedor_solo_ve_sus_preventas(): void
    {
        $mia = $this->sentOrder(1);
        $pedro = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);
        $ajena = EdtOrder::query()->create([
            'warehouse_id' => $this->oac->id, 'seller_id' => $pedro->id, 'client_id' => $this->hoy->id,
            'status' => EdtOrderStatus::Borrador, 'order_date' => today(),
        ]);

        $this->actingAs($this->juan->user);

        Livewire::test(ListEdtOrders::class)
            ->assertCanSeeTableRecords([$mia])
            ->assertCanNotSeeTableRecords([$ajena]);

        $this->get(EdtOrderResource::getUrl('view', ['record' => $ajena]))->assertNotFound();
    }

    // ── Bodega ──────────────────────────────────────────────────────

    public function test_la_bodega_ve_las_de_su_bodega_por_revisar_sin_borradores(): void
    {
        $enviada = $this->sentOrder(1);
        $borrador = app(EdtOrderService::class)->setQuantity(
            app(EdtOrderService::class)->draftFor($this->juan, $this->otroDia), $this->gallo, 1, 0,
        );

        $vendedorOas = EdtSeller::factory()->create(['warehouse_id' => $this->oas->id]);
        $clienteOas = EdtClient::factory()->inMunicipality('0401')->create(['warehouse_id' => $this->oas->id]);
        $deOas = EdtOrder::query()->create([
            'warehouse_id' => $this->oas->id, 'seller_id' => $vendedorOas->id, 'client_id' => $clienteOas->id,
            'status' => EdtOrderStatus::Borrador, 'order_date' => today(),
        ]);
        $deOas->forceFill(['status' => EdtOrderStatus::Enviado, 'number' => 'PED-999999'])->save();

        $this->actingAs($this->encargadoOac);

        Livewire::test(ListEdtOrders::class)
            ->assertActionHidden('newOrder')
            ->assertCanSeeTableRecords([$enviada])
            ->assertCanNotSeeTableRecords([$borrador, $deOas]);

        $this->get(EdtOrderResource::getUrl('view', ['record' => $deOas]))->assertNotFound();
    }

    public function test_la_bodega_baja_lo_que_no_hay_y_aprueba(): void
    {
        $order = $this->sentOrder(30);
        $line = $order->lines()->first();
        $this->actingAs($this->encargadoOac);

        Livewire::test(LinesRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewEdtOrder::class])
            ->assertTableActionVisible('adjust', $line)
            ->callTableAction('adjust', $line, data: ['boxes' => 40, 'units' => 0])
            ->assertNotified('No se puede dar más de lo que pidió el vendedor (30 CJ).')
            ->callTableAction('adjust', $line, data: ['boxes' => 20, 'units' => 0])
            ->assertNotified('Línea ajustada');

        $order->refresh();
        $this->assertSame(EdtOrderStatus::EnRevision, $order->status);
        $this->assertSame('8448.00', $order->total);

        Livewire::test(ViewEdtOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('El vendedor pidió L 12,300.00')
            ->assertActionHidden('edit')
            ->callAction('approve')
            ->assertNotified('Preventa aprobada');

        $this->assertSame(EdtOrderStatus::Aprobado, $order->fresh()->status);
        $this->assertSame($this->encargadoOac->id, $order->fresh()->reviewed_by);
    }

    public function test_rechazar_pide_motivo(): void
    {
        $order = $this->sentOrder(1);
        $this->actingAs($this->encargadoOac);

        Livewire::test(ViewEdtOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('reject', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        Livewire::test(ViewEdtOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('reject', data: ['reason' => 'cliente con saldo vencido'])
            ->assertNotified('Preventa rechazada');

        $this->assertSame(EdtOrderStatus::Rechazado, $order->fresh()->status);
        $this->assertSame('CLIENTE CON SALDO VENCIDO', $order->fresh()->close_reason);
    }

    public function test_el_vendedor_no_revisa_y_la_bodega_no_toma_preventas(): void
    {
        $order = $this->sentOrder(1);

        $this->actingAs($this->juan->user);
        Livewire::test(ViewEdtOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject')
            ->assertActionVisible('edit')
            ->assertActionVisible('cancel');

        $this->actingAs($this->encargadoOac);
        $this->get(EdtOrderResource::getUrl('pick'))->assertForbidden();
    }

    public function test_sin_permisos_del_edt_recibe_403(): void
    {
        $this->actingAs(User::factory()->forWarehouse($this->oac)->create(['is_active' => true]));

        $this->get(EdtOrderResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_cliente_con_preventas_no_se_borra(): void
    {
        $this->sentOrder(1);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->assertFalse($admin->can('delete', $this->hoy));
        $this->assertTrue($admin->can('delete', $this->otroDia));
    }

    private function sentOrder(int $boxes): EdtOrder
    {
        $orders = app(EdtOrderService::class);

        return $orders->submit($orders->setQuantity($orders->draftFor($this->juan, $this->hoy), $this->gallo, $boxes, 0));
    }
}
