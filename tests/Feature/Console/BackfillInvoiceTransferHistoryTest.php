<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Invoice;
use App\Models\InvoiceWarehouseTransfer;
use App\Models\Manifest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceWarehouseTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `invoices:backfill-transfer-history` — rescata a la tabla los traslados que
 * solo quedaron en activity_log (que se poda a los 90 días).
 *
 * Para simular un traslado "de antes de la tabla" se traslada con el servicio
 * real y luego se borra su fila del historial: activity_log queda exactamente
 * como lo dejó el traslado de GAEL el 27/08.
 */
class BackfillInvoiceTransferHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Jaremar la facturó a OAI; la entrega es de Santa Bárbara';

    private Warehouse $oai;

    private Warehouse $oas;

    private Manifest $manifest;

    private User $autorizado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oai = Warehouse::factory()->oai()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        $this->manifest = Manifest::factory()->create(['warehouse_id' => null]);

        Permission::findOrCreate(InvoiceWarehouseTransferService::PERMISSION, 'web');
        $role = Role::findOrCreate('tester-traslados', 'web');
        $role->givePermissionTo(InvoiceWarehouseTransferService::PERMISSION);
        $this->autorizado = User::factory()->create();
        $this->autorizado->assignRole($role);
    }

    public function test_rescata_un_traslado_que_solo_quedo_en_activity_log(): void
    {
        $invoice = $this->transferredBeforeTheTableExisted();

        $this->artisan('invoices:backfill-transfer-history')->assertSuccessful();

        $transfer = InvoiceWarehouseTransfer::query()->sole();

        $this->assertSame($invoice->id, $transfer->invoice_id);
        $this->assertSame($this->oai->id, $transfer->from_warehouse_id);
        $this->assertSame($this->oas->id, $transfer->to_warehouse_id);
        $this->assertSame($this->autorizado->id, $transfer->transferred_by);
        $this->assertSame(self::MOTIVO, $transfer->reason);
    }

    public function test_correrlo_dos_veces_no_duplica(): void
    {
        $this->transferredBeforeTheTableExisted();

        $this->artisan('invoices:backfill-transfer-history')->assertSuccessful();
        $this->artisan('invoices:backfill-transfer-history')->assertSuccessful();

        $this->assertSame(1, InvoiceWarehouseTransfer::query()->count());
    }

    public function test_no_duplica_lo_que_el_servicio_ya_guardo(): void
    {
        $invoice = $this->invoice();
        app(InvoiceWarehouseTransferService::class)
            ->transfer(collect([$invoice]), $this->oas, self::MOTIVO, $this->autorizado);

        $this->artisan('invoices:backfill-transfer-history')->assertSuccessful();

        $this->assertSame(1, InvoiceWarehouseTransfer::query()->count());
    }

    public function test_dry_run_no_escribe(): void
    {
        $this->transferredBeforeTheTableExisted();

        $this->artisan('invoices:backfill-transfer-history', ['--dry-run' => true])
            ->expectsOutputToContain('por rescatar: 1')
            ->assertSuccessful();

        $this->assertSame(0, InvoiceWarehouseTransfer::query()->count());
    }

    public function test_asignar_bodega_a_una_factura_sin_bodega_no_es_un_traslado(): void
    {
        $invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => null,
            'status' => 'pending_warehouse',
        ]);
        $invoice->update(['warehouse_id' => $this->oas->id, 'status' => 'imported']);

        $this->artisan('invoices:backfill-transfer-history')->assertSuccessful();

        $this->assertSame(0, InvoiceWarehouseTransfer::query()->count());
    }

    private function invoice(): Invoice
    {
        return Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->oai->id,
            'total' => 1000,
        ]);
    }

    private function transferredBeforeTheTableExisted(): Invoice
    {
        $invoice = $this->invoice();

        app(InvoiceWarehouseTransferService::class)
            ->transfer(collect([$invoice]), $this->oas, self::MOTIVO, $this->autorizado);

        InvoiceWarehouseTransfer::query()->delete();

        return $invoice;
    }
}
