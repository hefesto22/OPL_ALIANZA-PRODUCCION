<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Visibilidad del "Resumen Financiero" en la vista de manifiesto.
 *
 * Regla: se muestra a super_admin, admin, encargado y finance. Un usuario con
 * operador + finance DEBE verlo (tener finance basta); un operador puro NO.
 *
 * Regresión: antes la regla ocultaba la sección para cualquiera con rol
 * 'operador', lo que atrapaba a los usuarios que además eran 'finance'
 * (Jovany, Ana, Keyli) y les escondía el resumen indebidamente.
 */
class ManifestFinancialSummaryVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Manifest $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['operador', 'finance', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        foreach (['ViewAny:Manifest', 'View:Manifest'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        Role::findByName('operador')->givePermissionTo(['ViewAny:Manifest', 'View:Manifest']);
        Role::findByName('finance')->givePermissionTo(['ViewAny:Manifest', 'View:Manifest']);

        Supplier::factory()->create(['is_active' => true]);

        $this->warehouse = Warehouse::factory()->oac()->create();
        $this->manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouse->id]);

        // La visibilidad del manifiesto para usuarios de bodega se scopea por
        // las facturas de su bodega, así que le damos una factura en OAC.
        Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
        ]);
    }

    private function userWith(array $roles): User
    {
        // is_active = true es requisito de canAccessPanel (si no, 403 al abrir
        // cualquier página del panel, sin llegar a evaluar la visibilidad).
        $user = User::factory()->forWarehouse($this->warehouse)->create(['is_active' => true]);
        $user->assignRole($roles);

        return $user;
    }

    private function viewManifestAs(User $user)
    {
        return $this->actingAs($user)
            ->get(ManifestResource::getUrl('view', ['record' => $this->manifest]));
    }

    public function test_operador_plus_finance_sees_financial_summary(): void
    {
        $this->viewManifestAs($this->userWith(['operador', 'finance']))
            ->assertOk()
            ->assertSee('Resumen Financiero');
    }

    public function test_finance_only_sees_financial_summary(): void
    {
        $this->viewManifestAs($this->userWith(['finance']))
            ->assertOk()
            ->assertSee('Resumen Financiero');
    }

    public function test_operador_only_does_not_see_financial_summary(): void
    {
        $this->viewManifestAs($this->userWith(['operador']))
            ->assertOk()
            ->assertDontSee('Resumen Financiero');
    }
}
