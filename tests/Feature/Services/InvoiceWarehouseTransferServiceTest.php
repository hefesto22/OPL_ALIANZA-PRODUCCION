<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceWarehouseTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Traslado de facturas entre bodegas.
 *
 * El caso que originó este servicio: Jaremar facturó tres entregas de Santa
 * Bárbara con Almacen = OAI (Intibucá). La plata del manifiesto no cambia,
 * pero cambia QUIÉN la deposita y qué bodega la reporta como venta — así que
 * el traslado tiene que mover cuatro cosas al mismo tiempo o no mover
 * ninguna: la factura, sus devoluciones, los totales del manifiesto y el
 * rastro en la bitácora.
 *
 * El test más importante de este archivo es el de la fila huérfana: es el que
 * evita que la bodega vaciada siga apareciendo en el Reporte de Ventas por
 * Bodega con plata que ya no le pertenece.
 */
class InvoiceWarehouseTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceWarehouseTransferService $service;

    private Warehouse $oai;

    private Warehouse $oas;

    private Manifest $manifest;

    private User $autorizado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InvoiceWarehouseTransferService::class);
        $this->oai = Warehouse::factory()->oai()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        $this->manifest = Manifest::factory()->create(['warehouse_id' => null]);
        $this->autorizado = $this->userWithTransferPermission();
    }

    /**
     * Usuario global con el permiso personalizado de traslado.
     *
     * Espeja producción: allá el permiso lo tiene el rol super_admin asignado
     * en BD (define_via_gate=false, no hay Gate::before que conceda todo), así
     * que acá también se asigna explícito en vez de simular un atajo que no
     * existe.
     */
    private function userWithTransferPermission(): User
    {
        $permiso = Permission::findOrCreate(InvoiceWarehouseTransferService::PERMISSION, 'web');
        $rol = Role::findOrCreate('tester-traslados', 'web');
        $rol->givePermissionTo($permiso);

        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function invoiceIn(Warehouse $warehouse, float $total, string $clientId = 'CLI001'): Invoice
    {
        return Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $warehouse->id,
            'total' => $total,
            'client_id' => $clientId,
        ]);
    }

    private function totalsFor(Warehouse $warehouse): ?ManifestWarehouseTotal
    {
        return ManifestWarehouseTotal::query()
            ->where('manifest_id', $this->manifest->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
    }

    private const MOTIVO = 'Jaremar facturó a OAI por error; la entrega es de Santa Bárbara';

    // ── Traslado feliz ───────────────────────────────────────────────────

    public function test_moves_the_invoice_and_rebuilds_the_per_warehouse_totals(): void
    {
        $moved = $this->invoiceIn($this->oai, 1000, 'CLI001');
        $this->invoiceIn($this->oas, 500, 'CLI002');
        $this->manifest->recalculateTotals();

        $result = $this->service->transfer(collect([$moved]), $this->oas, self::MOTIVO, $this->autorizado);

        $this->assertSame(1, $result['invoices']);
        $this->assertEquals($this->oas->id, $moved->fresh()->warehouse_id);

        // OAS absorbe las dos facturas.
        $oasTotals = $this->totalsFor($this->oas);
        $this->assertEquals(1500.00, (float) $oasTotals->total_invoices);
        $this->assertSame(2, (int) $oasTotals->invoices_count);

        // El manifiesto factura lo mismo que antes: la plata no se creó ni se
        // destruyó, solo cambió de bodega.
        $this->assertEquals(1500.00, (float) $this->manifest->fresh()->total_invoices);
    }

    public function test_the_emptied_warehouse_row_is_deleted_not_left_behind(): void
    {
        $moved = $this->invoiceIn($this->oai, 1000, 'CLI001');
        $this->invoiceIn($this->oas, 500, 'CLI002');
        $this->manifest->recalculateTotals();

        $this->assertNotNull($this->totalsFor($this->oai), 'precondición: OAI arranca con su fila');

        $this->service->transfer(collect([$moved]), $this->oas, self::MOTIVO, $this->autorizado);

        // Sin esta limpieza, OAI seguiría acreditándose L 1,000 en el Reporte
        // de Ventas por Bodega y la suma de bodegas daría L 2,500 para un
        // manifiesto de L 1,500.
        $this->assertNull(
            $this->totalsFor($this->oai),
            'la bodega sin facturas no puede conservar su fila de totales'
        );

        $sumaPorBodega = (float) ManifestWarehouseTotal::query()
            ->where('manifest_id', $this->manifest->id)
            ->sum('total_invoices');

        $this->assertEquals(
            (float) $this->manifest->fresh()->total_invoices,
            $sumaPorBodega,
            'la suma por bodega tiene que dar el total del manifiesto'
        );
    }

    public function test_returns_travel_with_their_invoice(): void
    {
        $moved = $this->invoiceIn($this->oai, 1000, 'CLI001');

        $return = InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $this->manifest->id,
            'invoice_id' => $moved->id,
            'warehouse_id' => $this->oai->id,
            'total' => 250,
        ]);

        $this->manifest->recalculateTotals();

        $result = $this->service->transfer(collect([$moved]), $this->oas, self::MOTIVO, $this->autorizado);

        $this->assertSame(1, $result['returns']);
        $this->assertEquals($this->oas->id, $return->fresh()->warehouse_id);

        // La devolución resta en la bodega nueva, no en la vieja.
        $oasTotals = $this->totalsFor($this->oas);
        $this->assertEquals(250.00, (float) $oasTotals->total_returns);
        $this->assertEquals(750.00, (float) $oasTotals->total_to_deposit);
        $this->assertNull($this->totalsFor($this->oai));
    }

    public function test_an_invoice_without_warehouse_becomes_imported_when_assigned(): void
    {
        $pending = Invoice::factory()->pendingWarehouse()->create([
            'manifest_id' => $this->manifest->id,
            'total' => 400,
        ]);

        $this->service->transfer(collect([$pending]), $this->oas, self::MOTIVO, $this->autorizado);

        $fresh = $pending->fresh();
        $this->assertEquals($this->oas->id, $fresh->warehouse_id);
        $this->assertSame('imported', $fresh->status);
    }

    public function test_a_returned_invoice_keeps_its_lifecycle_status(): void
    {
        $devuelta = Invoice::factory()->returned()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->oai->id,
            'total' => 400,
        ]);

        $this->service->transfer(collect([$devuelta]), $this->oas, self::MOTIVO, $this->autorizado);

        // El status describe el ciclo de la factura, no su bodega.
        $this->assertSame('returned', $devuelta->fresh()->status);
    }

    public function test_an_invoice_already_in_the_target_warehouse_is_ignored(): void
    {
        $yaEsta = $this->invoiceIn($this->oas, 900);

        $result = $this->service->transfer(collect([$yaEsta]), $this->oas, self::MOTIVO, $this->autorizado);

        $this->assertSame(0, $result['invoices']);
        $this->assertSame(0, $result['manifests']);
    }

    // ── Bloqueos ─────────────────────────────────────────────────────────

    public function test_refuses_to_move_invoices_of_a_closed_manifest(): void
    {
        $cerrado = Manifest::factory()->closed()->create(['warehouse_id' => null]);
        $invoice = Invoice::factory()->create([
            'manifest_id' => $cerrado->id,
            'warehouse_id' => $this->oai->id,
            'total' => 1000,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cerrado/i');

        try {
            $this->service->transfer(collect([$invoice]), $this->oas, self::MOTIVO, $this->autorizado);
        } finally {
            $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
        }
    }

    public function test_refuses_a_transfer_without_a_usable_reason(): void
    {
        $invoice = $this->invoiceIn($this->oai, 1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/motivo/i');

        try {
            $this->service->transfer(collect([$invoice]), $this->oas, 'error', $this->autorizado);
        } finally {
            $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
        }
    }

    public function test_refuses_an_inactive_target_warehouse(): void
    {
        $inactiva = Warehouse::factory()->inactive()->create();
        $invoice = $this->invoiceIn($this->oai, 1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inactiva/i');

        $this->service->transfer(collect([$invoice]), $inactiva, self::MOTIVO, $this->autorizado);
    }

    public function test_moving_a_return_invalidates_the_cache_that_jaremar_reads(): void
    {
        $invoice = $this->invoiceIn($this->oai, 1000);

        // Emisión y proceso en días distintos a propósito: el bump golpea las
        // dos claves y si coincidieran el contador subiría de dos en dos
        // (comportamiento deliberado de ReturnService, ver su suite).
        $emision = now()->subDays(3)->toDateString();
        $proceso = now()->subDay()->toDateString();
        $invoice->update(['invoice_date' => $emision]);

        InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $this->manifest->id,
            'invoice_id' => $invoice->id,
            'warehouse_id' => $this->oai->id,
            'processed_date' => $proceso,
            'total' => 250,
        ]);

        Cache::put("devoluciones:version:{$proceso}", 10);
        Cache::put("devoluciones:version:{$emision}", 20);

        $this->service->transfer(collect([$invoice]), $this->oas, self::MOTIVO, $this->autorizado);

        // `devoluciones/listar` sirve el código de bodega desde ese cache: sin
        // el bump, Jaremar sigue leyendo OAI para una devolución que ya es de
        // OAS hasta que el cache expire solo.
        $this->assertSame(11, (int) Cache::get("devoluciones:version:{$proceso}"));
        $this->assertSame(21, (int) Cache::get("devoluciones:version:{$emision}"));
    }

    // ── Autorización ─────────────────────────────────────────────────────

    public function test_refuses_a_responsible_user_without_the_custom_permission(): void
    {
        // Update:Invoice NO alcanza: corregir datos de captura y mover plata
        // entre bodegas son dos poderes distintos, y ese es justo el punto del
        // permiso personalizado.
        $permisoDeEdicion = Permission::findOrCreate('Update:Invoice', 'web');
        $rol = Role::findOrCreate('tester-encargado', 'web');
        $rol->givePermissionTo($permisoDeEdicion);

        $encargado = User::factory()->create();
        $encargado->assignRole($rol);

        $invoice = $this->invoiceIn($this->oai, 1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TransferWarehouse:Invoice/');

        try {
            $this->service->transfer(collect([$invoice]), $this->oas, self::MOTIVO, $encargado);
        } finally {
            $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
        }
    }

    public function test_refuses_a_transfer_with_nobody_responsible(): void
    {
        $invoice = $this->invoiceIn($this->oai, 1000);

        // Sin causer explícito y sin sesión: es el caso de la consola corrida
        // por root. El acceso al servidor no puede ser una puerta trasera.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/responsable/i');

        try {
            $this->service->transfer(collect([$invoice]), $this->oas, self::MOTIVO);
        } finally {
            $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
        }
    }

    public function test_a_warehouse_user_cannot_pull_an_invoice_from_another_warehouse(): void
    {
        // Con el permiso marcado a mano pero alcance solo en OAS, la Policy
        // sigue frenando: no puede sacarle una factura a Intibucá.
        $permiso = Permission::findOrCreate(InvoiceWarehouseTransferService::PERMISSION, 'web');
        $rol = Role::findOrCreate('tester-traslados', 'web');
        $rol->givePermissionTo($permiso);

        $deOas = User::factory()->forWarehouse($this->oas)->create();
        $deOas->assignRole($rol);

        $invoice = $this->invoiceIn($this->oai, 1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/alcance/i');

        try {
            $this->service->transfer(collect([$invoice]), $this->oas, self::MOTIVO, $deOas);
        } finally {
            $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
        }
    }

    // ── Bitácora ─────────────────────────────────────────────────────────

    public function test_the_move_is_traceable_in_the_activity_log(): void
    {
        $invoice = $this->invoiceIn($this->oai, 1000);

        $this->service->transfer(collect([$invoice]), $this->oas, self::MOTIVO, $this->autorizado);

        // 1. La factura registra el cambio de bodega campo a campo.
        $invoiceLog = Activity::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($invoiceLog);
        $this->assertEquals($this->oai->id, $invoiceLog->properties['old']['warehouse_id']);
        $this->assertEquals($this->oas->id, $invoiceLog->properties['attributes']['warehouse_id']);

        // 2. El manifiesto registra el POR QUÉ, que es lo que ningún diff de
        //    columnas puede reconstruir después.
        $transferLog = Activity::query()
            ->where('subject_type', Manifest::class)
            ->where('subject_id', $this->manifest->id)
            ->where('event', 'warehouse_transfer')
            ->first();

        $this->assertNotNull($transferLog);
        $this->assertSame(self::MOTIVO, $transferLog->properties['motivo']);
        $this->assertSame('OAS', $transferLog->properties['bodega_destino']);
        $this->assertContains('OAI', $transferLog->properties['bodegas_origen']);
        $this->assertContains($invoice->invoice_number, $transferLog->properties['facturas']);
        $this->assertEquals($this->autorizado->id, $transferLog->causer_id);
    }

    // ── Plan (dry-run) ───────────────────────────────────────────────────

    public function test_the_plan_writes_nothing(): void
    {
        $invoice = $this->invoiceIn($this->oai, 1000);

        $plan = $this->service->plan(collect([$invoice]), $this->oas);

        $this->assertCount(1, $plan['movements']);
        $this->assertSame('OAI', $plan['movements'][0]['from_code']);
        $this->assertEquals(1000.00, $plan['total_amount']);
        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }
}
