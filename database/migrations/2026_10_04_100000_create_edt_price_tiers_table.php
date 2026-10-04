<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Escalas mayoristas del EDT (configurables, decisión de Mauricio 2026-10-03).
 *
 * Una escala aplica cuando el pedido lleva `min_boxes` cajas o más DEL MISMO
 * CÓDIGO (no el total del pedido). El precio mayorista por caja es:
 *
 *     ↑ lista × unidades_por_caja × (1 − descuento) × (1 + ISV)   (hacia arriba al lempira)
 *
 * Arranca con las dos escalas que calcula hoy el Excel del cliente
 * (celdas R1 y U1 de "Pedido EDTH"): 3% desde 25 cajas y 6% desde 50. Se
 * siembran aquí y no en un seeder porque son datos de configuración que el
 * cálculo de precios necesita en TODOS los entornos (en prod no se corren
 * seeders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edt_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedInteger('min_boxes')->unique();
            $table->decimal('discount_pct', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_by');
            $table->index('updated_by');
        });

        DB::statement(
            'ALTER TABLE edt_price_tiers ADD CONSTRAINT edt_price_tiers_min_boxes_positive CHECK (min_boxes >= 1)'
        );
        DB::statement(
            'ALTER TABLE edt_price_tiers ADD CONSTRAINT edt_price_tiers_discount_range '.
            'CHECK (discount_pct >= 0 AND discount_pct < 100)'
        );

        DB::table('edt_price_tiers')->insert([
            ['name' => 'MAYORISTA 1', 'min_boxes' => 25, 'discount_pct' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'MAYORISTA 2', 'min_boxes' => 50, 'discount_pct' => 6, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('edt_price_tiers');
    }
};
