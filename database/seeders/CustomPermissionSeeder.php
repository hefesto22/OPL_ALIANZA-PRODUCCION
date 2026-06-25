<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los permisos personalizados que NO derivan de un Resource de
 * Filament — protegen botones/acciones que `shield:generate` no cubre.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  POR QUÉ EXISTE ESTE SEEDER
 * ──────────────────────────────────────────────────────────────────────
 *  `shield:generate` solo genera permisos para los métodos estándar de
 *  Policy (viewAny, view, create, update, delete, restore, forceDelete…).
 *  Pero hay acciones de negocio con botón propio que necesitan control
 *  granular y no mapean a un método estándar:
 *
 *    • Cerrar / Reabrir manifiesto   (ManifestsTable)
 *    • Exportar PDF / Excel de depósitos      (ListDeposits)
 *    • Exportar PDF / Excel de devoluciones   (ListReturns)
 *
 *  Antes estos botones se controlaban con checks de rol inline
 *  (`hasRole('admin')`) — anti-patrón que contradice "toda acción tiene
 *  Policy". Aquí declaramos los permisos en formato Shield
 *  (Pascal + ':' separador) para que:
 *    1. Existan en BD y RolePermissionSeeder los pueda asignar.
 *    2. Aparezcan en la pestaña "Permisos personalizados" del editor de
 *       roles (config/filament-shield.php → custom_permissions + tab).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ORDEN DE EJECUCIÓN
 * ──────────────────────────────────────────────────────────────────────
 *  Debe correr ANTES de RolePermissionSeeder (que los asigna a los roles)
 *  y DESPUÉS de migrate (que crea la tabla permissions). En el bootstrap
 *  va justo antes de RolePermissionSeeder. Es idempotente (firstOrCreate).
 *
 *  El super_admin NO necesita estos permisos explícitos: el
 *  intercept_gate='before' de Shield le concede cualquier ability.
 */
class CustomPermissionSeeder extends Seeder
{
    /**
     * Permisos personalizados del sistema.
     * Formato Shield: 'Accion:Modelo' (pascal, separador ':').
     * Mantener sincronizado con config/filament-shield.php → custom_permissions.
     *
     * @var array<int, string>
     */
    public const PERMISSIONS = [
        'Close:Manifest',
        'Reopen:Manifest',
        'ExportPdf:Deposit',
        'ExportExcel:Deposit',
        'ExportPdf:InvoiceReturn',
        'ExportExcel:InvoiceReturn',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web']
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(
            '[CustomPermissionSeeder] '.count(self::PERMISSIONS).' permisos personalizados provistos.'
        );
    }
}
