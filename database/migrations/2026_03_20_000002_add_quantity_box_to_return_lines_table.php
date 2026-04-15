<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `quantity_box` a return_lines para poder registrar y exportar
 * por separado las cajas devueltas de las unidades sueltas devueltas.
 *
 * Formato Jaremar requiere CantidadCaja y Cantidad como columnas distintas.
 * Antes solo se guardaba `quantity` (unidades totales), por lo que CantidadCaja
 * siempre salía en 0 en la exportación.
 *
 * - quantity_box:   cajas devueltas (enteras). Default 0.
 * - quantity:       permanece igual — unidades sueltas / fracciones devueltas.
 *
 * Los registros históricos quedan con quantity_box = 0, que es el valor
 * correcto para exportar en el formato Jaremar (no se puede reconstruir
 * retroactivamente la separación cajas/unidades).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_lines', function (Blueprint $table) {
            // Se agrega después de line_number para mantener orden lógico con el modelo.
            $table->decimal('quantity_box', 10, 4)
                ->default(0)
                ->after('line_number')
                ->comment('Número de cajas enteras devueltas (Jaremar: CantidadCaja)');
        });
    }

    public function down(): void
    {
        Schema::table('return_lines', function (Blueprint $table) {
            $table->dropColumn('quantity_box');
        });
    }
};
