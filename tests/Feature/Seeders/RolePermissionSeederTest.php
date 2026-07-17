<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests del RolePermissionSeeder.
 *
 * Cubrimos:
 *  - Matriz de permisos por rol: cada rol recibe exactamente lo que le toca.
 *  - Graceful skip: sin permisos en BD no rompe, solo advierte.
 *  - super_admin no recibe permisos (gateado vía intercept_gate).
 *  - Idempotencia: correr 2 veces deja el mismo estado.
 *  - syncPermissions sobrescribe permisos manuales (la matriz es ley).
 *
 * No corremos shield:generate aquí — creamos los permisos manualmente
 * para que los tests sean rápidos y deterministas. El end-to-end con
 * shield:generate real lo cubre SystemFreshBootstrapTest.
 */
class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Crea los 5 roles vacíos (super_admin, admin, encargado, operador, finance).
        $this->seed(RoleSeeder::class);
    }

    /**
     * Crea los permisos en formato Shield (PascalCase con ':') que la
     * matriz espera encontrar. Espejo de los modelos que tiene el sistema.
     */
    private function seedShieldPermissions(): void
    {
        $models = [
            'Activity', 'Deposit', 'Invoice', 'InvoiceReturn', 'Manifest',
            'ReturnReason', 'Role', 'User', 'Warehouse',
        ];

        $actions = [
            'ViewAny', 'View', 'Create', 'Update', 'Delete',
            'Restore', 'ForceDelete', 'ForceDeleteAny', 'RestoreAny',
            'Replicate', 'Reorder',
        ];

        foreach ($models as $model) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$action}:{$model}", 'guard_name' => 'web']
                );
            }
        }

        // Permisos custom (los crea CustomPermissionSeeder en runtime real).
        // Aquí los sembramos a mano para que la matriz pueda asignarlos.
        foreach (\Database\Seeders\CustomPermissionSeeder::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_graceful_skip_when_no_permissions_exist(): void
    {
        // Sin permisos en BD → no rompe, solo advierte y vuelve.
        $this->seed(RolePermissionSeeder::class);

        foreach (['admin', 'encargado', 'operador', 'finance'] as $role) {
            $this->assertSame(
                0,
                Role::query()->where('name', $role)->first()->permissions()->count(),
                "El rol {$role} no debería tener permisos si Shield no corrió."
            );
        }
    }

    public function test_admin_receives_full_crud_except_role_management(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::query()->where('name', 'admin')->first();
        $permissions = $admin->permissions->pluck('name')->all();

        // Tiene CRUD sobre los modelos de negocio
        $this->assertContains('ViewAny:Manifest', $permissions);
        $this->assertContains('Update:Manifest', $permissions);
        $this->assertContains('Delete:Manifest', $permissions);
        $this->assertContains('Create:Deposit', $permissions);
        $this->assertContains('Create:ReturnReason', $permissions);
        $this->assertContains('Create:User', $permissions);

        // NO debe poder gestionar roles/permisos
        $this->assertNotContains('ViewAny:Role', $permissions);
        $this->assertNotContains('Create:Role', $permissions);
        $this->assertNotContains('Delete:Role', $permissions);
    }

    public function test_encargado_can_edit_warehouse_data_but_not_destroy(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $encargado = Role::query()->where('name', 'encargado')->first();
        $permissions = $encargado->permissions->pluck('name')->all();

        // Puede ver y editar
        $this->assertContains('ViewAny:Invoice', $permissions);
        $this->assertContains('Update:Invoice', $permissions);
        $this->assertContains('Update:Manifest', $permissions);
        $this->assertContains('Create:InvoiceReturn', $permissions);
        $this->assertContains('Create:Deposit', $permissions);

        // No puede borrar nada (corrige, no destruye)
        $this->assertNotContains('Delete:Invoice', $permissions);
        $this->assertNotContains('Delete:Manifest', $permissions);
        $this->assertNotContains('Delete:Deposit', $permissions);

        // No gestiona usuarios ni roles
        $this->assertNotContains('ViewAny:User', $permissions);
        $this->assertNotContains('ViewAny:Role', $permissions);
    }

    public function test_operador_is_read_only_except_creating_returns(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $operador = Role::query()->where('name', 'operador')->first();
        $permissions = $operador->permissions->pluck('name')->all();

        // Solo lectura sobre facturas y manifiestos
        $this->assertContains('ViewAny:Invoice', $permissions);
        $this->assertContains('View:Invoice', $permissions);
        $this->assertContains('ViewAny:Manifest', $permissions);

        // Excepción: puede CREAR devoluciones (captura del operador)
        $this->assertContains('Create:InvoiceReturn', $permissions);

        // No puede editar nada
        $this->assertNotContains('Update:Invoice', $permissions);
        $this->assertNotContains('Update:Manifest', $permissions);
        $this->assertNotContains('Update:InvoiceReturn', $permissions);

        // No toca depósitos
        $this->assertNotContains('ViewAny:Deposit', $permissions);
    }

    public function test_finance_owns_deposits_and_reads_invoices(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $finance = Role::query()->where('name', 'finance')->first();
        $permissions = $finance->permissions->pluck('name')->all();

        // Depósitos: CRUD parcial
        $this->assertContains('ViewAny:Deposit', $permissions);
        $this->assertContains('Create:Deposit', $permissions);
        $this->assertContains('Update:Deposit', $permissions);

        // Lectura de facturas y manifiestos (para conciliar)
        $this->assertContains('ViewAny:Invoice', $permissions);
        $this->assertContains('ViewAny:Manifest', $permissions);

        // No edita facturas
        $this->assertNotContains('Update:Invoice', $permissions);
        $this->assertNotContains('Delete:Invoice', $permissions);
    }

    public function test_custom_button_permissions_are_assigned_per_matrix(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $perms = fn (string $role): array => Role::query()
            ->where('name', $role)->first()->permissions->pluck('name')->all();

        // ── admin: cierra y reabre manifiestos, exporta todo ──
        $admin = $perms('admin');
        $this->assertContains('Close:Manifest', $admin);
        $this->assertContains('Reopen:Manifest', $admin);
        $this->assertContains('ExportPdf:Deposit', $admin);
        $this->assertContains('ExportExcel:InvoiceReturn', $admin);

        // ── encargado: cierra (su bodega) pero NO reabre ──
        $encargado = $perms('encargado');
        $this->assertContains('Close:Manifest', $encargado);
        $this->assertNotContains('Reopen:Manifest', $encargado);
        $this->assertContains('ExportExcel:Deposit', $encargado);

        // ── operador: solo PDF de devoluciones, nada de Excel ni cerrar ──
        $operador = $perms('operador');
        $this->assertContains('ExportPdf:InvoiceReturn', $operador);
        $this->assertNotContains('ExportExcel:InvoiceReturn', $operador);
        $this->assertNotContains('Close:Manifest', $operador);

        // ── finance: exporta depósitos, no toca manifiestos ni devoluciones ──
        $finance = $perms('finance');
        $this->assertContains('ExportPdf:Deposit', $finance);
        $this->assertContains('ExportExcel:Deposit', $finance);
        $this->assertNotContains('ExportPdf:InvoiceReturn', $finance);
        $this->assertNotContains('Close:Manifest', $finance);
    }

    /**
     * Pestañas y botones de la vista del manifiesto (permisos custom).
     *
     * Regresión del fix 2026-07-08: la visibilidad era un blacklist de rol
     * (`! hasRole('operador')`) que ocultaba Depósitos/Devoluciones a los
     * usuarios multi-rol operador+finance. Ahora es permiso por pestaña/botón
     * y esta matriz lo protege.
     */
    public function test_manifest_view_tab_and_button_permissions_per_matrix(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $perms = fn (string $role): array => Role::query()
            ->where('name', $role)->first()->permissions->pluck('name')->all();

        // ── admin y encargado: ven todo el bloque financiero del manifiesto ──
        foreach (['admin', 'encargado'] as $role) {
            $rolePerms = $perms($role);
            $this->assertContains('ViewDeposits:Manifest', $rolePerms);
            $this->assertContains('ViewReturns:Manifest', $rolePerms);
            $this->assertContains('ExportInvoicesPdf:Manifest', $rolePerms);
            $this->assertContains('ExportProductsPdf:Manifest', $rolePerms);
            $this->assertContains('ExportChecklistPdf:Manifest', $rolePerms);
            $this->assertContains('ExportReturnsPdf:Manifest', $rolePerms);
        }

        // ── operador: sublistas operativas SÍ, datos financieros NO ──
        $operador = $perms('operador');
        $this->assertContains('ExportProductsPdf:Manifest', $operador);
        $this->assertContains('ExportChecklistPdf:Manifest', $operador);
        $this->assertNotContains('ViewDeposits:Manifest', $operador);
        $this->assertNotContains('ViewReturns:Manifest', $operador);
        $this->assertNotContains('ExportInvoicesPdf:Manifest', $operador);
        $this->assertNotContains('ExportReturnsPdf:Manifest', $operador);

        // ── finance: pestañas financieras + Reporte PDF de facturas ──
        $finance = $perms('finance');
        $this->assertContains('ViewDeposits:Manifest', $finance);
        $this->assertContains('ViewReturns:Manifest', $finance);
        $this->assertContains('ExportInvoicesPdf:Manifest', $finance);
        $this->assertNotContains('ExportProductsPdf:Manifest', $finance);
        $this->assertNotContains('ExportChecklistPdf:Manifest', $finance);
        $this->assertNotContains('ExportReturnsPdf:Manifest', $finance);
    }

    /**
     * Reportes globales del listado de manifiestos (permisos custom 2026-07-17).
     *
     * finance imprime PDF / Sin ISV / Excel (scoped a sus bodegas vía payload),
     * pero NO el comparativo por bodega — ese se asigna caso por caso desde
     * Shield → Permisos personalizados.
     */
    public function test_manifest_list_report_permissions_per_matrix(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $perms = fn (string $role): array => Role::query()
            ->where('name', $role)->first()->permissions->pluck('name')->all();

        // ── admin: los 4 reportes del listado ──
        $admin = $perms('admin');
        foreach (['ReportPdf', 'ReportPdfSinIsv', 'ReportWarehouseSales', 'ExportExcel'] as $action) {
            $this->assertContains("{$action}:Manifest", $admin);
        }

        // ── finance: PDF, Sin ISV y Excel — comparativo por bodega NO ──
        $finance = $perms('finance');
        $this->assertContains('ReportPdf:Manifest', $finance);
        $this->assertContains('ReportPdfSinIsv:Manifest', $finance);
        $this->assertContains('ExportExcel:Manifest', $finance);
        $this->assertNotContains('ReportWarehouseSales:Manifest', $finance);

        // ── encargado y operador: ninguno de los reportes del listado ──
        foreach (['encargado', 'operador'] as $role) {
            $rolePerms = $perms($role);
            $this->assertNotContains('ReportPdf:Manifest', $rolePerms);
            $this->assertNotContains('ReportPdfSinIsv:Manifest', $rolePerms);
            $this->assertNotContains('ReportWarehouseSales:Manifest', $rolePerms);
            $this->assertNotContains('ExportExcel:Manifest', $rolePerms);
        }
    }

    public function test_super_admin_receives_no_explicit_permissions(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = Role::query()->where('name', 'super_admin')->first();

        // super_admin queda VACÍO de permisos explícitos.
        // El acceso total se lo da Gate::before configurado por Shield
        // (config/filament-shield.php: super_admin.intercept_gate='before').
        $this->assertSame(0, $superAdmin->permissions()->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedShieldPermissions();

        $this->seed(RolePermissionSeeder::class);
        $firstRunCounts = $this->roleCounts();

        $this->seed(RolePermissionSeeder::class);
        $secondRunCounts = $this->roleCounts();

        $this->assertSame(
            $firstRunCounts,
            $secondRunCounts,
            'El seeder no debe agregar ni quitar permisos al re-ejecutar.'
        );
    }

    public function test_sync_overrides_manual_permissions_added_from_panel(): void
    {
        $this->seedShieldPermissions();
        $this->seed(RolePermissionSeeder::class);

        // Simulamos que alguien agregó manualmente Delete:Manifest a un operador.
        $operador = Role::query()->where('name', 'operador')->first();
        $operador->givePermissionTo('Delete:Manifest');
        $this->assertTrue($operador->hasPermissionTo('Delete:Manifest'));

        // Al re-correr el seeder, la matriz es ley → el permiso manual se va.
        $this->seed(RolePermissionSeeder::class);

        $this->assertFalse(
            $operador->fresh()->hasPermissionTo('Delete:Manifest'),
            'syncPermissions debe quitar permisos manuales no declarados en la matriz.'
        );
    }

    /**
     * @return array<string, int>
     */
    private function roleCounts(): array
    {
        $counts = [];
        foreach (['super_admin', 'admin', 'encargado', 'operador', 'finance'] as $role) {
            $counts[$role] = Role::query()->where('name', $role)->first()->permissions()->count();
        }

        return $counts;
    }
}
