<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asigna permisos a los roles del sistema según la matriz de negocio.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ORDEN DE EJECUCIÓN
 * ──────────────────────────────────────────────────────────────────────
 *  Este seeder requiere que los permisos YA existan en la BD. El flujo
 *  correcto es:
 *
 *    1. php artisan migrate           (crea tabla permissions)
 *    2. php artisan db:seed           (crea roles vacíos vía RoleSeeder)
 *    3. php artisan shield:generate --all   (genera permisos Shield)
 *    4. php artisan db:seed --class=RolePermissionSeeder  (asigna)
 *
 *  Si este seeder se invoca ANTES del paso 3, detecta que no hay
 *  permisos y aborta gracefully con un warning — no rompe el flujo.
 *  Por eso es seguro incluirlo en DatabaseSeeder.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ESTRATEGIA DE ASIGNACIÓN: syncPermissions (fuente de verdad)
 * ──────────────────────────────────────────────────────────────────────
 *  Usamos syncPermissions() en lugar de givePermissionTo() para que el
 *  seeder sea la única fuente de verdad de la matriz: si alguien agrega
 *  un permiso manual desde /admin/shield/roles, la próxima ejecución
 *  del seeder lo revertirá al estado declarado aquí.
 *
 *  Esto es intencional: queremos que la matriz de permisos sea
 *  versionable, revisable en PR, y reproducible en cualquier entorno
 *  (local, staging, producción).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  EL SUPER_ADMIN NO RECIBE ASIGNACIÓN
 * ──────────────────────────────────────────────────────────────────────
 *  config/filament-shield.php tiene super_admin.intercept_gate='before'
 *  → Shield registra un Gate::before que devuelve true para super_admin
 *  sobre CUALQUIER permiso. Asignarle permisos explícitamente sería
 *  duplicar lógica y crear sorpresas (¿qué pasa si alguien quita un
 *  permiso del super_admin desde el panel? Nada — el gate gana).
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Modelos cortos sobre los que Shield genera permisos.
     * Mantener sincronizado con los Resources de Filament.
     */
    private const MODELS = [
        'Activity',
        'Deposit',
        'Invoice',
        'InvoiceReturn',
        'Manifest',
        'ReturnReason',
        'Role',
        'User',
        'Warehouse',
    ];

    /**
     * Matriz de permisos por rol y modelo.
     *
     * Estructura: [rol => [modelo => [acciones...]]]
     *
     * Las acciones se traducen a permisos Shield concatenando con ':':
     *   ViewAny + Manifest → 'ViewAny:Manifest'
     *
     * Filtrado por bodega: NO va aquí. Lo aplican las Policies + el
     * trait HandlesWarehouseScope (userOwnsRecord) sobre el warehouse_id
     * del usuario autenticado. La matriz solo dice "qué acciones".
     */
    private const MATRIX = [
        // ── admin: gestor general OPL Alianza ──────────────────────
        // Ve y opera todo el sistema EXCEPTO gestionar roles/permisos
        // (eso queda reservado al super_admin para evitar escaladas).
        'admin' => [
            'Activity' => ['ViewAny', 'View'],
            'Deposit' => ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore', 'ExportPdf', 'ExportExcel'],
            'Invoice' => ['ViewAny', 'View', 'Update', 'Delete', 'Restore'],
            'InvoiceReturn' => ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore', 'ExportPdf', 'ExportExcel'],
            'Manifest' => ['ViewAny', 'View', 'Update', 'Delete', 'Restore', 'Close', 'Reopen'],
            'ReturnReason' => ['ViewAny', 'View', 'Create', 'Update', 'Delete'],
            'User' => ['ViewAny', 'View', 'Create', 'Update'],
            'Warehouse' => ['ViewAny', 'View', 'Update'],
            // Role: NO. Solo super_admin gestiona la matriz de seguridad.
        ],

        // ── encargado: supervisor de bodega ────────────────────────
        // Corrige errores de captura de su equipo. Puede editar
        // facturas/manifiestos de SU bodega (Policy lo filtra),
        // registra/edita devoluciones y depósitos.
        'encargado' => [
            'Deposit' => ['ViewAny', 'View', 'Create', 'Update', 'ExportPdf', 'ExportExcel'],
            'Invoice' => ['ViewAny', 'View', 'Update'],
            'InvoiceReturn' => ['ViewAny', 'View', 'Create', 'Update', 'ExportPdf', 'ExportExcel'],
            // Close: el encargado cierra manifiestos de SU bodega (la Policy
            // lo filtra). Reopen NO — reabrir es sensible, queda en admin.
            'Manifest' => ['ViewAny', 'View', 'Update', 'Close'],
            'ReturnReason' => ['ViewAny', 'View'],
            'Warehouse' => ['ViewAny', 'View'],
        ],

        // ── operador: trabajo diario de bodega ─────────────────────
        // Consulta manifiestos, factura, imprime PDFs y captura
        // devoluciones. No edita, no borra.
        'operador' => [
            'Invoice' => ['ViewAny', 'View'],
            // Captura devoluciones e imprime su PDF. NO exporta Excel.
            'InvoiceReturn' => ['ViewAny', 'View', 'Create', 'ExportPdf'],
            'Manifest' => ['ViewAny', 'View'],
            'ReturnReason' => ['ViewAny', 'View'],
            'Warehouse' => ['ViewAny', 'View'],
        ],

        // ── finance: registra depósitos bancarios ──────────────────
        // Lectura de facturas/manifiestos de su bodega + CRUD de
        // depósitos para conciliación bancaria.
        'finance' => [
            'Deposit' => ['ViewAny', 'View', 'Create', 'Update', 'ExportPdf', 'ExportExcel'],
            'Invoice' => ['ViewAny', 'View'],
            'InvoiceReturn' => ['ViewAny', 'View'],
            'Manifest' => ['ViewAny', 'View'],
            'Warehouse' => ['ViewAny', 'View'],
        ],
    ];

    public function run(): void
    {
        // Limpia caché de Spatie para que no operemos sobre snapshot viejo.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ── Pre-flight check ───────────────────────────────────────
        // Si Shield aún no generó permisos, abortamos sin romper.
        // Esto permite que el seeder esté en DatabaseSeeder sin
        // forzar un orden estricto de ejecución.
        $allPermissions = Permission::query()->pluck('name');

        if ($allPermissions->isEmpty()) {
            $this->command?->warn(
                '[RolePermissionSeeder] Saltado: no hay permisos en la BD. '.
                "Ejecuta primero: php artisan shield:generate --all\n".
                'Luego: php artisan db:seed --class=RolePermissionSeeder'
            );

            return;
        }

        // ── Asignación por rol ─────────────────────────────────────
        foreach (self::MATRIX as $roleName => $modelPermissions) {
            $role = Role::query()->where('name', $roleName)->first();

            if (! $role) {
                $this->command?->warn(
                    "[RolePermissionSeeder] Rol '{$roleName}' no existe — corre RoleSeeder primero."
                );

                continue;
            }

            $permissionsToAssign = $this->resolvePermissions(
                $modelPermissions,
                $allPermissions,
                $roleName
            );

            // syncPermissions reemplaza completamente: la matriz es ley.
            $role->syncPermissions($permissionsToAssign);

            $this->command?->info(
                "[RolePermissionSeeder] {$roleName}: ".count($permissionsToAssign).' permisos asignados.'
            );
        }

        // Refrescar caché para que la próxima request vea los cambios.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Resuelve la lista de nombres de permisos para un rol.
     *
     * Para cada modelo en la matriz, genera "Accion:Modelo" y verifica
     * que ese permiso exista realmente en la BD. Si Shield aún no ha
     * generado un permiso (p.ej. agregamos un Resource nuevo y olvidamos
     * correr shield:generate), lo reportamos como warning en lugar de
     * romper la asignación entera.
     *
     * @param  array<string, array<string>>  $modelPermissions  ['Manifest' => ['ViewAny', 'View'], ...]
     * @param  Collection<int, string>  $allPermissions  Todos los permisos existentes en la BD
     * @return array<string>
     */
    private function resolvePermissions(
        array $modelPermissions,
        Collection $allPermissions,
        string $roleName
    ): array {
        $resolved = [];
        $missing = [];

        foreach ($modelPermissions as $model => $actions) {
            foreach ($actions as $action) {
                $permissionName = "{$action}:{$model}";

                if ($allPermissions->contains($permissionName)) {
                    $resolved[] = $permissionName;
                } else {
                    $missing[] = $permissionName;
                }
            }
        }

        if (! empty($missing)) {
            $this->command?->warn(
                "[RolePermissionSeeder] Permisos faltantes para '{$roleName}' ".
                '(¿olvidaste shield:generate --all?): '.implode(', ', $missing)
            );
        }

        return $resolved;
    }
}
