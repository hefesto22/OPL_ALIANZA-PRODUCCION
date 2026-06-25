<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-bodega por usuario: relación muchos-a-muchos users ↔ warehouses.
 *
 * Antes: cada usuario tenía UN warehouse_id (una bodega). Ahora un usuario
 * puede supervisar varias bodegas (caso encargado regional).
 *
 * Flujo de la migración (un solo paso, seguro en pre-producción con pocos
 * usuarios — el backfill es trivial):
 *   1. Crear la tabla pivote user_warehouse.
 *   2. Backfill: copiar el warehouse_id actual de cada usuario al pivote.
 *   3. Eliminar la columna users.warehouse_id (el pivote es la única fuente
 *      de verdad — enfoque A).
 *
 * "Global" (admin / super_admin) = usuario SIN filas en el pivote → ve todo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Un usuario no puede tener la misma bodega dos veces.
            $table->unique(['user_id', 'warehouse_id'], 'user_warehouse_unique');
            // Índice para resolver "¿qué usuarios tiene esta bodega?" (notif importador).
            $table->index('warehouse_id', 'user_warehouse_warehouse_idx');
        });

        // ── Backfill: warehouse_id actual → pivote ────────────────────────
        if (Schema::hasColumn('users', 'warehouse_id')) {
            DB::statement('
                INSERT INTO user_warehouse (user_id, warehouse_id, created_at, updated_at)
                SELECT id, warehouse_id, now(), now()
                FROM users
                WHERE warehouse_id IS NOT NULL
            ');

            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('warehouse_id');
            });
        }
    }

    public function down(): void
    {
        // Restaura la columna y recupera la PRIMERA bodega de cada usuario
        // (best-effort: si un usuario tenía varias, conserva una sola).
        if (! Schema::hasColumn('users', 'warehouse_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('warehouse_id')
                    ->nullable()
                    ->after('avatar_url')
                    ->constrained()
                    ->nullOnDelete();
            });

            DB::statement('
                UPDATE users u
                SET warehouse_id = sub.warehouse_id
                FROM (
                    SELECT user_id, MIN(warehouse_id) AS warehouse_id
                    FROM user_warehouse
                    GROUP BY user_id
                ) sub
                WHERE u.id = sub.user_id
            ');
        }

        Schema::dropIfExists('user_warehouse');
    }
};
