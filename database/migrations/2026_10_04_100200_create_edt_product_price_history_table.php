<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de precios del EDT: una fila por cada cambio que mueve el
 * precio de un producto. Reemplaza las filas repetidas por código del Excel
 * del cliente ("un código = un producto; cada cambio queda en historial",
 * Mauricio 2026-10-03).
 *
 * Solo se le AGREGAN filas: el modelo bloquea update y delete. Es permanente
 * (no lo toca ninguna limpieza).
 *
 * Cada fila guarda:
 *  - los DATOS DE ENTRADA vigentes en ese momento (lista, ISV, unidades por
 *    caja, descuento del proveedor), y
 *  - la FOTO de los precios que resultaron (costo, detalle, mayoristas).
 * La foto no se recalcula nunca: es cómo estaba el precio en esa fecha.
 *
 * product_id con restrictOnDelete: un producto con historial no se borra
 * (se desactiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edt_product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('edt_products')->restrictOnDelete();
            $table->string('reason', 30);
            $table->string('note', 255)->nullable();

            // Datos de entrada vigentes al momento del cambio.
            $table->decimal('list_price', 12, 4);
            $table->unsignedInteger('units_per_box');
            $table->decimal('isv_pct', 5, 2);
            $table->decimal('operation_discount_pct', 5, 2);

            // Foto de los precios resultantes (con ISV).
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('retail_unit_price', 12, 2);
            $table->decimal('retail_box_price', 14, 2);
            // [{name, min_boxes, discount_pct, box_price}, ...] según las
            // escalas activas en ese momento.
            $table->jsonb('wholesale_prices');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // La consulta típica: el historial de UN producto, del más nuevo
            // al más viejo. El índice compuesto también cubre la FK.
            $table->index(['product_id', 'created_at']);
            $table->index('created_by');
        });

        DB::statement(
            'ALTER TABLE edt_product_price_history ADD CONSTRAINT edt_price_history_reason_valid '.
            "CHECK (reason IN ('creacion', 'lista_proveedor', 'factura_compra', 'correccion', ".
            "'edicion', 'descuento_proveedor', 'escalas', 'carga_inicial'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('edt_product_price_history');
    }
};
