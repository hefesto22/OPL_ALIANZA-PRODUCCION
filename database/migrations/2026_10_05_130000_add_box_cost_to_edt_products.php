<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costo por caja propio de cada producto del EDT (con ISV).
 *
 * Hasta hoy el costo se calculaba: lista − descuento del proveedor (15%) +
 * ISV. Pero el descuento por contrato puede ser distinto en cada producto,
 * así que el costo pasa a ser un dato que se escribe (al crear y en
 * «Cambiar precio»); el 15% del proveedor queda solo como sugerencia y para
 * la importación del Excel.
 *
 * Los productos que ya existen arrancan con el costo que el sistema les
 * calculaba (la misma fórmula, en SQL): nada cambia de precio al migrar.
 * El historial guarda también el costo por caja de cada foto; en las filas
 * viejas se reconstruye desde el costo por unidad que ya guardaban.
 *
 * Tablas chicas (cientos de productos): el backfill va aquí mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edt_products', function (Blueprint $table) {
            $table->decimal('box_cost', 14, 4)->nullable()->after('list_price');
        });

        DB::statement(<<<'SQL'
            UPDATE edt_products AS p
            SET box_cost = ROUND(p.list_price * (1 - s.operation_discount_pct / 100) * (1 + p.isv_pct / 100) * p.units_per_box, 4)
            FROM edt_suppliers AS s
            WHERE s.id = p.supplier_id
        SQL);

        DB::statement('ALTER TABLE edt_products ALTER COLUMN box_cost SET NOT NULL');
        DB::statement('ALTER TABLE edt_products ADD CONSTRAINT edt_products_box_cost_positive CHECK (box_cost > 0)');

        Schema::table('edt_product_price_history', function (Blueprint $table) {
            $table->decimal('box_cost', 14, 4)->nullable()->after('unit_cost');
        });

        DB::statement('UPDATE edt_product_price_history SET box_cost = ROUND(unit_cost * units_per_box, 4)');
        DB::statement('ALTER TABLE edt_product_price_history ALTER COLUMN box_cost SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('edt_product_price_history', function (Blueprint $table) {
            $table->dropColumn('box_cost');
        });

        DB::statement('ALTER TABLE edt_products DROP CONSTRAINT IF EXISTS edt_products_box_cost_positive');

        Schema::table('edt_products', function (Blueprint $table) {
            $table->dropColumn('box_cost');
        });
    }
};
