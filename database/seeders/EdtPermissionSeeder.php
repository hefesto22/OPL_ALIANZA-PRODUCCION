<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Edt\EdtModule;
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
 *  nada. Desde la fase 4 además da su bloque del EDT al encargado y crea el
 *  rol vendedor_edt con el suyo (ROLE_PERMISSIONS). Es idempotente, así que
 *  es seguro correrlo en cada fase del EDT y en cualquier entorno,
 *  producción incluida.
 *
 *  Al agregar un modelo nuevo al EDT: sumarlo a PERMISSIONS_BY_MODEL (y a
 *  ROLE_PERMISSIONS si otro rol lo usa) y a la matriz de
 *  RolePermissionSeeder. EdtPermissionSeederTest verifica que coincidan
 *  entre sí y con la Policy.
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
        // Sin Delete: un vendedor con historial de rutas se desactiva.
        'EdtSeller' => ['ViewAny', 'View', 'Create', 'Update'],
        // AssignSeller es custom (también en CustomPermissionSeeder).
        'EdtRoute' => ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'AssignSeller'],
    ];

    /**
     * Bloque del EDT de los roles que no son globales (subconjunto de
     * PERMISSIONS_BY_MODEL). Debe ser igual al de la matriz de
     * RolePermissionSeeder para esos roles.
     *
     * - encargado: clientes, vendedores y rutas de SU bodega (las Policies
     *   filtran) y consulta del catálogo. Sin borrar ni cambiar precios.
     * - vendedor_edt: consulta sus clientes (solo los de sus rutas) y el
     *   catálogo con precios.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public const ROLE_PERMISSIONS = [
        'encargado' => [
            'EdtClient' => ['ViewAny', 'View', 'Create', 'Update'],
            'EdtSeller' => ['ViewAny', 'View', 'Create', 'Update'],
            'EdtRoute' => ['ViewAny', 'View', 'Create', 'Update', 'AssignSeller'],
            'EdtProduct' => ['ViewAny', 'View'],
        ],
        EdtModule::SELLER_ROLE => [
            'EdtClient' => ['ViewAny', 'View'],
            'EdtProduct' => ['ViewAny', 'View'],
        ],
    ];

    /**
     * Nombres completos de los permisos del EDT, ej. 'ViewAny:EdtSupplier'.
     *
     * @return array<int, string>
     */
    public static function permissionNames(): array
    {
        return self::namesFor(self::PERMISSIONS_BY_MODEL);
    }

    /**
     * ['EdtClient' => ['ViewAny', 'View']] → ['ViewAny:EdtClient', 'View:EdtClient'].
     *
     * @param  array<string, array<int, string>>  $permissionsByModel
     * @return array<int, string>
     */
    public static function namesFor(array $permissionsByModel): array
    {
        $names = [];

        foreach ($permissionsByModel as $model => $actions) {
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

        $partial = [];
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionsByModel) {
            // firstOrCreate: vendedor_edt no existe en los entornos que se
            // bootstrapearon antes de la fase 4.
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $names = self::namesFor($permissionsByModel);
            $role->givePermissionTo($names);
            $partial[] = "{$roleName}: ".count($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(
            '[EdtPermissionSeeder] '.count($permissionNames).' permisos del EDT provistos a '.
            implode(', ', self::roleNames()).'; '.implode('; ', $partial).'.'
        );
    }
}
