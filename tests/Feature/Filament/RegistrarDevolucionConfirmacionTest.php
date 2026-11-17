<?php

namespace Tests\Feature\Filament;

use App\Enums\ReturnPublicationMode;
use App\Filament\Resources\Manifests\Pages\ViewManifest;
use App\Filament\Resources\Manifests\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Returns\Schemas\FinalReturnConfirmation;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReturnSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Botón "Devolver" del manifiesto (2026-11-17): con "Al momento" la
 * devolución queda definitiva al guardarse, así que el modal muestra el
 * aviso y no deja registrar sin marcar la casilla de responsabilidad. En
 * los otros modos la casilla no aparece y el flujo sigue igual.
 *
 * Ninguno de estos casos manda cantidades: si la validación del modal pasa,
 * la acción responde "Debes ingresar al menos un producto", y eso basta
 * para saber que la casilla no la frenó.
 */
class RegistrarDevolucionConfirmacionTest extends TestCase
{
    use RefreshDatabase;

    private const SIN_PRODUCTOS = 'Debes ingresar al menos un producto a devolver.';

    private User $user;

    private Manifest $manifest;

    private Invoice $invoice;

    private ReturnReason $reason;

    private InvoiceLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

        $permisos = ['ViewAny:Manifest', 'View:Manifest', 'ViewAny:Invoice', 'View:Invoice'];
        foreach ($permisos as $permiso) {
            Permission::create(['name' => $permiso, 'guard_name' => 'web']);
        }
        $role->givePermissionTo($permisos);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('super_admin');

        $warehouse = Warehouse::factory()->oac()->create();
        $this->reason = ReturnReason::factory()->create();

        // El TestCase base deja la ventana en 10000 días: el manifiesto
        // sigue abierto durante todo el test.
        $this->manifest = Manifest::factory()->create([
            'date' => now()->toDateString(),
            'status' => 'imported',
            'warehouse_id' => $warehouse->id,
        ]);

        $this->invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'imported',
        ]);

        $this->line = InvoiceLine::factory()->create([
            'invoice_id' => $this->invoice->id,
            'quantity_fractions' => 10,
            'quantity_box' => 0,
            'conversion_factor' => 1,
        ]);

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->user);
    }

    private function publicar(ReturnPublicationMode $mode, int $delayDays): void
    {
        app(ReturnSettingsService::class)->update(7, $mode, $delayDays, false, $this->user);
    }

    private function facturasDelManifiesto()
    {
        return Livewire::test(InvoicesRelationManager::class, [
            'ownerRecord' => $this->manifest,
            'pageClass' => ViewManifest::class,
        ]);
    }

    public function test_al_momento_no_deja_registrar_sin_marcar_la_casilla(): void
    {
        $this->publicar(ReturnPublicationMode::AfterRegistration, 0);

        $this->facturasDelManifiesto()
            ->callTableAction('registrar_devolucion', $this->invoice, data: [
                'return_reason_id' => $this->reason->id,
            ])
            ->assertHasFormErrors([FinalReturnConfirmation::FIELD => 'accepted']);

        $this->assertSame(0, InvoiceReturn::count());
    }

    public function test_al_momento_con_la_casilla_marcada_pasa_la_validacion(): void
    {
        $this->publicar(ReturnPublicationMode::AfterRegistration, 0);

        $this->facturasDelManifiesto()
            ->callTableAction('registrar_devolucion', $this->invoice, data: [
                'return_reason_id' => $this->reason->id,
                FinalReturnConfirmation::FIELD => true,
            ])
            ->assertNotified(self::SIN_PRODUCTOS);
    }

    public function test_con_el_paquete_al_cierre_no_pide_la_casilla(): void
    {
        $this->publicar(ReturnPublicationMode::Window, 0);

        $this->facturasDelManifiesto()
            ->callTableAction('registrar_devolucion', $this->invoice, data: [
                'return_reason_id' => $this->reason->id,
            ])
            ->assertNotified(self::SIN_PRODUCTOS);
    }

    public function test_el_modal_muestra_la_factura_y_una_fila_por_producto(): void
    {
        $this->facturasDelManifiesto()
            ->mountTableAction('registrar_devolucion', $this->invoice)
            ->assertMountedActionModalSee([
                'Devolver factura '.$this->invoice->invoice_number,
                'Total factura',
                'A devolver',
                $this->line->product_description,
            ]);
    }
}
