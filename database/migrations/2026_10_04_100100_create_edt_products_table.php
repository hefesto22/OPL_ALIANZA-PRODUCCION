<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de productos del EDT.
 *
 * NO hay inventario (restricción de negocio): el producto es un catálogo
 * con lo necesario para calcular precios. Los precios de venta y el costo
 * NO se guardan aquí: los calcula EdtPricingService a partir de estos datos,
 * así nunca quedan desactualizados. La foto de cada cambio va a
 * edt_product_price_history.
 *
 * - list_price: precio de lista del proveedor SIN ISV (columna "PVD - ISV"
 *   del Excel). Es también el precio de venta al detalle sin ISV. Va con 4
 *   decimales porque el cliente a veces lo saca de un precio con ISV
 *   (8.6957 × 1.15 = 10.00); con 2 decimales esos productos darían 10.01.
 * - isv_pct: 0 (exento), 15 o 18, configurado por producto como en el Excel.
 * - supplier_id con restrictOnDelete: no se puede borrar un proveedor que
 *   tenga productos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edt_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('edt_suppliers')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('description', 200);
            $table->string('category', 60)->nullable();
            $table->string('family', 60)->nullable();
            $table->string('presentation', 60)->nullable();
            $table->unsignedInteger('units_per_box');
            $table->decimal('isv_pct', 5, 2);
            $table->decimal('list_price', 12, 4);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Toda FK lleva índice (Postgres no lo crea solo).
            $table->index('supplier_id');
            $table->index('created_by');
            $table->index('updated_by');
        });

        DB::statement(
            'ALTER TABLE edt_products ADD CONSTRAINT edt_products_units_per_box_positive CHECK (units_per_box >= 1)'
        );
        DB::statement(
            'ALTER TABLE edt_products ADD CONSTRAINT edt_products_isv_valid CHECK (isv_pct IN (0, 15, 18))'
        );
        DB::statement(
            'ALTER TABLE edt_products ADD CONSTRAINT edt_products_list_price_positive CHECK (list_price > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('edt_products');
    }
};
