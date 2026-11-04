<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración → Devoluciones (2026-11-04).
 *
 * Lo que antes solo se cambiaba en el .env del servidor ahora se elige desde
 * el panel, por entorno (pruebas y producción tienen cada uno su fila):
 *
 *   - return_settings: UNA sola fila (CHECK id = 1).
 *       window_business_days    días hábiles para registrar devoluciones de
 *                               un manifiesto. NULL = el valor del servidor
 *                               (config api.devoluciones_ventana_dias_habiles),
 *                               así un entorno que nunca abrió la pantalla
 *                               sigue exactamente como estaba.
 *       publication_mode        'window': Jaremar ve el paquete del manifiesto
 *                               completo al cerrar la ventana (lo de hoy).
 *                               'after_registration': ve cada devolución
 *                               publication_delay_days días después de
 *                               registrada (0 = al momento).
 *
 *   - returns.published_at: cuándo queda visible para Jaremar ESA devolución.
 *     Se fija al registrarla, así que cambiar el ajuste después no esconde
 *     ni adelanta nada que ya estaba decidido. NULL = sigue la ventana del
 *     manifiesto (todas las devoluciones que ya existen).
 *     Sin índice a propósito: el API lo filtra con published_at <= ahora,
 *     que deja pasar casi todas las filas; el filtro selectivo es la fecha
 *     de la factura.
 *
 * Aditiva: la columna nueva es NULL sin default (Postgres no reescribe la
 * tabla) y la fila arranca con el comportamiento actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('window_business_days')->nullable();
            $table->string('publication_mode', 20)->default('window');
            $table->unsignedSmallInteger('publication_delay_days')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('updated_by');
        });

        DB::statement('ALTER TABLE return_settings ADD CONSTRAINT return_settings_single_row CHECK (id = 1)');
        DB::statement(
            'ALTER TABLE return_settings ADD CONSTRAINT return_settings_window_valid '.
            'CHECK (window_business_days IS NULL OR window_business_days BETWEEN 1 AND 30)'
        );
        DB::statement(
            'ALTER TABLE return_settings ADD CONSTRAINT return_settings_mode_valid '.
            "CHECK (publication_mode IN ('window', 'after_registration'))"
        );
        DB::statement(
            'ALTER TABLE return_settings ADD CONSTRAINT return_settings_delay_valid '.
            'CHECK (publication_delay_days BETWEEN 0 AND 30)'
        );

        DB::table('return_settings')->insert([
            'id' => 1,
            'window_business_days' => null,
            'publication_mode' => 'window',
            'publication_delay_days' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('returns', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });

        Schema::dropIfExists('return_settings');
    }
};
