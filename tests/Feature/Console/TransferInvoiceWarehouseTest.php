<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceWarehouseTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Puerta de consola del traslado de bodega.
 *
 * Lo que se protege acá no es la lógica del traslado (esa vive en
 * InvoiceWarehouseTransferServiceTest) sino los frenos del comando: que el
 * dry-run no escriba, que no se pueda mover plata sin motivo, y que un número
 * de factura ambiguo aborte en vez de adivinar.
 */
class TransferInvoiceWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Jaremar facturó a OAI por error; la entrega es de Santa Bárbara';

    private Warehouse $oai;

    private Warehouse $oas;

    private Manifest $manifest;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oai = Warehouse::factory()->oai()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        $this->manifest = Manifest::factory()->create(['warehouse_id' => null]);

        $permiso = Permission::findOrCreate(InvoiceWarehouseTransferService::PERMISSION, 'web');
        $rol = Role::findOrCreate('tester-traslados', 'web');
        $rol->givePermissionTo($permiso);

        $this->responsable = User::factory()->create();
        $this->responsable->assignRole($rol);
    }

    private function invoice(string $number, float $total = 1000): Invoice
    {
        return Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->oai->id,
            'invoice_number' => $number,
            'total' => $total,
        ]);
    }

    public function test_dry_run_shows_the_plan_and_writes_nothing(): void
    {
        $invoice = $this->invoice('002-001-01-03949657');

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('MODO DRY-RUN')
            ->assertSuccessful();

        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }

    public function test_transfers_after_confirmation(): void
    {
        $invoice = $this->invoice('002-001-01-03949657');

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
            '--reason' => self::MOTIVO,
        ])
            ->expectsConfirmation('¿Trasladar 1 factura(s) por L 1,000.00 a OAS?', 'yes')
            ->assertSuccessful();

        $this->assertEquals($this->oas->id, $invoice->fresh()->warehouse_id);
    }

    public function test_answering_no_leaves_everything_where_it_was(): void
    {
        $invoice = $this->invoice('002-001-01-03949657');

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
            '--reason' => self::MOTIVO,
        ])
            ->expectsConfirmation('¿Trasladar 1 factura(s) por L 1,000.00 a OAS?', 'no')
            ->assertSuccessful();

        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }

    public function test_refuses_to_run_without_a_reason(): void
    {
        $invoice = $this->invoice('002-001-01-03949657');

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
        ])->assertFailed();

        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }

    public function test_refuses_an_unknown_warehouse_code(): void
    {
        $this->invoice('002-001-01-03949657');

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAX',
            '--user' => $this->responsable->email,
            '--reason' => self::MOTIVO,
        ])->assertFailed();
    }

    public function test_refuses_an_unknown_invoice_number(): void
    {
        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['999-999-99-99999999'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
            '--reason' => self::MOTIVO,
        ])->assertFailed();
    }

    public function test_an_ambiguous_suffix_aborts_instead_of_guessing(): void
    {
        // Dos facturas de series distintas terminan igual. Elegir una a dedo
        // sería mover plata a la bodega equivocada por comodidad.
        $a = $this->invoice('002-001-01-03949657');
        $b = $this->invoice('003-001-01-03949657', 2000);

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['03949657'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
            '--reason' => self::MOTIVO,
        ])->assertFailed();

        $this->assertEquals($this->oai->id, $a->fresh()->warehouse_id);
        $this->assertEquals($this->oai->id, $b->fresh()->warehouse_id);
    }

    public function test_a_unique_suffix_resolves_to_its_invoice(): void
    {
        $invoice = $this->invoice('002-001-01-03949657');

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['03949657'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('002-001-01-03949657')
            ->assertSuccessful();

        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }

    public function test_refuses_to_touch_a_closed_manifest(): void
    {
        $cerrado = Manifest::factory()->closed()->create(['warehouse_id' => null]);
        $invoice = Invoice::factory()->create([
            'manifest_id' => $cerrado->id,
            'warehouse_id' => $this->oai->id,
            'invoice_number' => '002-001-01-03949999',
            'total' => 1000,
        ]);

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949999'],
            '--to' => 'OAS',
            '--user' => $this->responsable->email,
            '--reason' => self::MOTIVO,
        ])->assertFailed();

        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }

    public function test_refuses_to_run_without_a_responsible_user(): void
    {
        $invoice = $this->invoice('002-001-01-03949657');

        // Correr el comando como root del servidor no es una credencial.
        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAS',
            '--reason' => self::MOTIVO,
        ])->assertFailed();

        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }

    public function test_refuses_a_responsible_user_without_the_permission(): void
    {
        $sinPermiso = User::factory()->create();
        $invoice = $this->invoice('002-001-01-03949657');

        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAS',
            '--user' => $sinPermiso->email,
            '--reason' => self::MOTIVO,
        ])->assertFailed();

        $this->assertEquals($this->oai->id, $invoice->fresh()->warehouse_id);
    }

    public function test_the_dry_run_is_denied_to_a_user_without_the_permission(): void
    {
        $sinPermiso = User::factory()->create();
        $this->invoice('002-001-01-03949657');

        // Ni siquiera el preview: quien no puede ejecutar el traslado tampoco
        // necesita ver el desglose de montos por bodega.
        $this->artisan('invoices:transfer-warehouse', [
            '--invoice' => ['002-001-01-03949657'],
            '--to' => 'OAS',
            '--user' => $sinPermiso->email,
            '--dry-run' => true,
        ])->assertFailed();
    }
}
