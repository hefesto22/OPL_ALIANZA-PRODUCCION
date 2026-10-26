<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial durable de traslados de facturas entre bodegas.
 *
 * Hasta ahora el "de dónde venía" de una factura trasladada solo quedaba en
 * activity_log, que se poda a los 90 días (routes/console.php). El reporte de
 * ventas necesita mostrar OAI → OAS para cualquier período, así que el
 * traslado se guarda aquí, donde no se poda.
 *
 * Tabla nueva y aditiva: no toca invoices ni bloquea nada en producción.
 * Rollback: drop de la tabla (el historial vuelve a vivir solo en activity_log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // NULL = la factura no tenía bodega (pending_warehouse) y se le asignó.
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('transferred_at');
            $table->timestamps();

            // Toda FK lleva índice; invoice_id va con la fecha porque el
            // reporte arma la cadena de traslados de cada factura en orden.
            $table->index(['invoice_id', 'transferred_at']);
            $table->index('from_warehouse_id');
            $table->index('to_warehouse_id');
            $table->index('transferred_by');

            // Evita duplicar el mismo traslado si el rescate corre dos veces.
            $table->unique(['invoice_id', 'to_warehouse_id', 'transferred_at'], 'invoice_transfers_unique_move');
        });

        DB::statement(
            'ALTER TABLE invoice_warehouse_transfers ADD CONSTRAINT invoice_transfers_distinct_warehouses '
            .'CHECK (from_warehouse_id IS DISTINCT FROM to_warehouse_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_warehouse_transfers');
    }
};
