<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique constraint compuesto sobre invoice_lines (invoice_id, line_number).
 *
 * Es una invariante natural del dominio: una factura no puede tener dos
 * líneas con el mismo número. Sin la constraint, el upsert idempotente del
 * importer (necesario para retry seguro del job) no puede usar esas dos
 * columnas como conflict target.
 *
 * Estado pre-producción: tabla vacía, no hay backfill ni riesgo de
 * duplicados existentes. Si la migración corre con datos, falla limpio
 * (no hay registros) y se puede aplicar de inmediato.
 *
 * Si en el futuro entra un INSERT que viole esta constraint, el motor lo
 * rechazará con QueryException — eso es el comportamiento correcto: un
 * duplicado en la misma factura es un bug del importer, no un caso válido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unique(['invoice_id', 'line_number'], 'invoice_lines_invoice_line_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropUnique('invoice_lines_invoice_line_unique');
        });
    }
};
