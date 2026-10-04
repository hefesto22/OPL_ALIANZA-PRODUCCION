<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores del módulo EDT.
 *
 * Tabla propia y separada de `suppliers` (que es el proveedor de la API de
 * Jaremar y guarda su api_key). El EDT no reutiliza nada de esa tabla.
 *
 * operation_discount_pct: el "Descuento Operación" del Excel de precios del
 * cliente. Es el descuento que el proveedor le da al EDT sobre su precio de
 * lista, o sea la ganancia del EDT (costo = lista × (1 − descuento)). NO es
 * el ISV. Se guarda en porcentaje (15.00 = 15%) y va por proveedor, con 15%
 * por defecto (decisión de Mauricio, 2026-10-03).
 *
 * Sin softDeletes: un proveedor se desactiva con is_active. Cuando existan
 * productos (fase 2) la FK desde edt_products va con restrictOnDelete, así
 * que no se podrá borrar un proveedor que tenga catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edt_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            // RTN hondureño: 14 dígitos, se guarda sin guiones.
            $table->string('rtn', 14)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('operation_discount_pct', 5, 2)->default(15);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Toda FK lleva índice (Postgres no lo crea solo).
            $table->index('created_by');
            $table->index('updated_by');
        });

        // Invariantes en el motor: la app valida lo mismo en el formulario,
        // pero el CHECK es la última línea de defensa (imports, tinker, seeders).
        DB::statement(
            'ALTER TABLE edt_suppliers ADD CONSTRAINT edt_suppliers_operation_discount_range '.
            'CHECK (operation_discount_pct >= 0 AND operation_discount_pct < 100)'
        );
        DB::statement(
            'ALTER TABLE edt_suppliers ADD CONSTRAINT edt_suppliers_rtn_format '.
            "CHECK (rtn IS NULL OR rtn ~ '^[0-9]{14}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('edt_suppliers');
    }
};
