<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Saca el módulo EDT de Hozana (2026-11-03).
 *
 * El EDT se fue a su propio proyecto, aparte del flujo de Jaremar. Su código
 * ya no está en este repositorio; esta migración borra lo que dejó en la base
 * de datos de los entornos donde llegó a correr (local y pruebas):
 *
 *   - Las tablas edt_* y las de geografía (hn_departments, hn_municipalities),
 *     que solo usaba el EDT. Las secuencias del código de cliente y del número
 *     de pedido son OWNED BY sus tablas y se van con ellas; el DROP SEQUENCE
 *     IF EXISTS es solo por si alguna quedó suelta.
 *   - Los permisos «Accion:Edt…» (Spatie borra sus asignaciones a roles y
 *     usuarios en cascada).
 *   - El rol vendedor_edt. Los usuarios que SOLO tenían ese rol quedan
 *     inactivos, no se borran: pueden tener bitácora y registros created_by
 *     en otras tablas. Quien además tenía otro rol lo conserva y sigue activo.
 *   - Las filas de `migrations` de las migraciones del EDT, cuyos archivos ya
 *     no existen, para que migrate:status no las arrastre.
 *
 * Donde el EDT nunca corrió (producción, CI, una BD nueva) no hace nada: todo
 * es IF EXISTS o un DELETE que no encuentra filas. Correrla dos veces no
 * cambia nada. Laravel ya la envuelve en una transacción (Postgres): si algo
 * falla, no queda a medias.
 *
 * La bitácora (activity_log con log_name 'edt') no se toca: ya no es
 * permanente y la limpieza diaria (activitylog:prune) la borra a los 90 días
 * como al resto.
 *
 * NO es reversible: down() no recrea nada. Para recuperar el módulo hay que
 * revertir el commit que lo quitó, que trae de vuelta sus migraciones.
 */
return new class extends Migration
{
    /**
     * Todas en un solo DROP: Postgres resuelve las FK entre ellas. Sin
     * CASCADE a propósito: si alguna tabla de Jaremar apuntara a una de
     * estas, la migración falla en vez de borrarle la FK en silencio.
     */
    private const TABLES = [
        'edt_order_lines',
        'edt_orders',
        'edt_route_assignments',
        'edt_route_municipality',
        'edt_routes',
        'edt_sellers',
        'edt_clients',
        'edt_product_price_history',
        'edt_products',
        'edt_price_tiers',
        'edt_suppliers',
        'hn_municipalities',
        'hn_departments',
    ];

    private const SEQUENCES = [
        'edt_client_code_seq',
        'edt_order_number_seq',
    ];

    /** Migraciones del EDT que se quitaron del repositorio. */
    private const MIGRATIONS = [
        '2026_10_03_200000_create_edt_suppliers_table',
        '2026_10_04_100000_create_edt_price_tiers_table',
        '2026_10_04_100100_create_edt_products_table',
        '2026_10_04_100200_create_edt_product_price_history_table',
        '2026_10_04_200000_create_hn_geography_tables',
        '2026_10_04_200100_create_edt_clients_table',
        '2026_10_04_210000_create_edt_sellers_table',
        '2026_10_04_210100_create_edt_routes_tables',
        '2026_10_05_130000_add_box_cost_to_edt_products',
        '2026_10_06_100000_create_edt_orders_tables',
    ];

    private const SELLER_ROLE = 'vendedor_edt';

    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS '.implode(', ', self::TABLES));

        foreach (self::SEQUENCES as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }

        $this->dropSellerRole();

        DB::table(config('permission.table_names.permissions'))
            ->where('name', 'like', '%:Edt%')
            ->delete();

        DB::table(config('database.migrations.table'))
            ->whereIn('migration', self::MIGRATIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No reversible (ver arriba): el módulo EDT vive en su propio proyecto.
    }

    /**
     * Desactiva a los usuarios cuyo único rol era vendedor_edt y borra el rol
     * (model_has_roles y role_has_permissions se van en cascada).
     */
    private function dropSellerRole(): void
    {
        $roles = config('permission.table_names.roles');
        $modelHasRoles = config('permission.table_names.model_has_roles');
        $roleKey = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $modelKey = config('permission.column_names.model_morph_key');

        $roleId = DB::table($roles)->where('name', self::SELLER_ROLE)->value('id');

        if ($roleId === null) {
            return;
        }

        $userMorph = (new User)->getMorphClass();

        $sellerUserIds = DB::table($modelHasRoles)
            ->where($roleKey, $roleId)
            ->where('model_type', $userMorph)
            ->pluck($modelKey);

        $withOtherRole = DB::table($modelHasRoles)
            ->whereIn($modelKey, $sellerUserIds)
            ->where('model_type', $userMorph)
            ->where($roleKey, '!=', $roleId)
            ->pluck($modelKey);

        DB::table('users')
            ->whereIn('id', $sellerUserIds->diff($withOtherRole)->values())
            ->update(['is_active' => false, 'updated_at' => now()]);

        DB::table($roles)->where('id', $roleId)->delete();
    }
};
