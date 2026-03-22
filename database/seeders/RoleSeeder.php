<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Crea los roles del sistema.
 *
 * ⚠️  IMPORTANTE — Orden de despliegue:
 *   1. php artisan migrate
 *   2. php artisan db:seed                    ← crea roles y usuario admin
 *   3. php artisan shield:generate --all      ← crea permisos en formato Shield
 *   4. Asignar permisos a roles desde el panel: /admin/shield/roles
 *
 * Los permisos NO se asignan aquí porque Shield los genera en su propio
 * formato (PascalCase con dos puntos, ej: ViewAny:Manifest) y deben
 * asignarse DESPUÉS de que shield:generate haya corrido.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Roles del sistema ──────────────────────────────────────
        //
        //  Roles globales (warehouse_id = null) → ven todo el sistema:
        //    super_admin  → Mauricio, acceso total sin restricciones
        //    admin        → Administrador OPL Alianza, gestión general
        //
        //  Roles de bodega (warehouse_id asignado) → solo su bodega:
        //    encargado    → Supervisor de bodega, corrige errores de su equipo
        //    operador     → Operador, consulta manifiestos y devoluciones (impresión)
        //    finance      → Finanzas, registra depósitos
        //
        $roles = [
            Utils::getSuperAdminName(), // super_admin
            'admin',
            'encargado',
            'operador',
            'finance',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name'       => $role],
                ['guard_name' => 'web']
            );
        }
    }
}
