<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca las devoluciones registradas FUERA de la ventana hábil del
 * manifiesto (excepción autorizada por el permiso
 * `RegisterAfterDeadline:InvoiceReturn`, 2026-09-22).
 *
 * Por qué una columna y no solo el activity log: estas devoluciones son
 * las únicas que Jaremar puede no haber consumido — su paquete de emisión
 * ya se publicó cerrado. Tener la bandera en la tabla permite listarlas en
 * una query y avisarle a Jaremar qué fechas de emisión debe re-consultar.
 *
 * Seguridad en producción: ADD COLUMN con default constante NO reescribe
 * la tabla en PostgreSQL 11+ (metadata-only), así que no bloquea `returns`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->boolean('after_deadline')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('after_deadline');
        });
    }
};
