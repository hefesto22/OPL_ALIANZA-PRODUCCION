<?php

declare(strict_types=1);

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del módulo EDT para entornos que YA están bootstrapeados
 * (local, pruebas.hozana.cloud y producción).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  POR QUÉ EXISTE (Y NO BASTA CON RolePermissionSeeder)
 * ──────────────────────────────────────────────────────────────────────
 *  En un bootstrap desde cero los permisos del EDT salen de
 *  `shield:generate`, el super_admin los recibe de `shield:super-admin` y
 *  el admin de RolePermissionSeeder. Pero en un entorno que ya existe:
 *
 *    - El super_admin NO recibe solo un permiso nuevo (define_via_gate =
 *      false: es un rol con permisos explícitos en BD).
 *    - En producción RolePermissionSeeder no se corre nunca: hace
 *      syncPermissions y borraría los ajustes hechos a mano desde Shield.
 *
 *  Este seeder es ADITIVO: crea los permisos que falten (firstOrCreate) y
 *  se los da a super_admin y admin con givePermissionTo, sin quitarles
 *  nada. Es idempotente, así que es seguro correrlo en cada fase del EDT y
 *  en cualquier entorno, producción incluida.
 *
 *  Al agregar un modelo nuevo al EDT (productos, clientes, vendedores…):
 *  sumarlo a PERMISSIONS_BY_MODEL y a la matriz de RolePermissionSeeder.
 *  EdtPermissionSeederTest verifica que coincidan entre sí y con la Policy.
 *
 *  Nota: shield:generate crea además Restore/ForceDelete/Replicate/Reorder
 *  para cada Resource (policies.merge = true en config/filament-shield.php
 *  hace que resources.manage sume métodos, no que los limite). Esos extras
 *  no abren nada: la Policy del EDT no los consulta.
 */
class EdtPermissionSeeder extends Seeder
{
    /**
     * Acciones Shield por modelo del EDT (formato 'Accion:Modelo').
     *
     * @var array<string, array<int, string>>
     */
    public const PERMISSIONS_BY_MODEL = [
        'EdtSupplier' => ['ViewAny', 'View', 'Create', 'Update', 'Delete'],
        // Sin Delete: un producto con historial se desactiva. ChangePrice es
        // custom (también en CustomPermissionSeeder para los bootstraps).
        'EdtProduct' => ['ViewAny', 'View', 'Create', 'Update', 'ChangePrice'],
        'EdtPriceTier' => ['ViewAny', 'View', 'Create', 'Update', 'Delete'],
        // Clientes: la Policy además limita a los usuarios de bodega a sus
        // propias bodegas (HandlesWarehouseScope).
        'EdtClient' => ['ViewAny', 'View', 'Create', 'Update', 'Delete'],
    ];

    /**
     * Nombres completos de los permisos del EDT, ej. 'ViewAny:EdtSupplier'.
     *
     * @return array<int, string>
     */
    public static function permissionNames(): array
    {
        $names = [];

        foreach (self::PERMISSIONS_BY_MODEL as $model => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$action}:{$model}";
            }
        }

        return $names;
    }

    /**
     * Roles que reciben todos los permisos del EDT al arrancar el módulo.
     * Los demás roles se habilitan cuando el negocio lo defina.
     *
     * @return array<int, string>
     */
    public static function roleNames(): array
    {
        return [Utils::getSuperAdminName(), 'admin'];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = self::permissionNames();

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::roleNames() as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if (! $role) {
                $this->command?->warn("[EdtPermissionSeeder] El rol '{$roleName}' no existe — se omite.");

                continue;
            }

            // Aditivo: no quita ningún permiso que el rol ya tenga.
            $role->givePermissionTo($permissionNames);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(
            '[EdtPermissionSeeder] '.count($permissionNames).' permisos del EDT provistos a '.
            implode(', ', self::roleNames()).'.'
        );
    }
}
