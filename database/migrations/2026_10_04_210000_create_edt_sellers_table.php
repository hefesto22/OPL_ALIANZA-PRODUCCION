<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendedores de ruta del EDT.
 *
 * Cada vendedor tiene su propio usuario (user_id, uno a uno) con el rol
 * vendedor_edt, para entrar desde el celular. El nombre se guarda aquí en
 * MAYÚSCULAS (regla del EDT) y se copia al usuario.
 *
 * - warehouse_id: su bodega. Todo lo que vende se factura desde ahí, y solo
 *   puede tener rutas de esa bodega (FK compuesta en edt_route_assignments,
 *   que apunta a unique(id, warehouse_id) de esta tabla).
 * - Sin softDeletes ni borrado: un vendedor se desactiva (y su usuario ya no
 *   entra). Su historial de rutas se conserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edt_sellers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Listado por bodega; también es el índice de la FK warehouse_id.
            $table->index(['warehouse_id', 'name'], 'edt_sellers_warehouse_name_idx');
            // Destino de la FK compuesta (seller_id, warehouse_id) de las asignaciones.
            $table->unique(['id', 'warehouse_id'], 'edt_sellers_id_warehouse_unique');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edt_sellers');
    }
};
