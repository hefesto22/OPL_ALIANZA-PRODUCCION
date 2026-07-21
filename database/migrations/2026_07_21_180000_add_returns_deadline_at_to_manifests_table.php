<?php

use App\Support\BusinessDays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fecha límite de registro de devoluciones por manifiesto.
     *
     * Regla operativa (2026-07-21): las devoluciones de un manifiesto solo
     * pueden registrarse dentro de N días HÁBILES (lun–sáb, domingo no
     * cuenta) desde su llegada, contando el día de llegada como día 1.
     * Al cierre (día N a las 11:59 pm hora Honduras) el paquete se publica
     * a Jaremar y queda congelado (ni crear, ni editar, ni cancelar).
     *
     * La fecha se PERSISTE (no se calcula al vuelo) para poder filtrar en
     * SQL en el endpoint de Jaremar (`returns_deadline_at < now()`), mostrar
     * la cuenta regresiva en la tabla de manifiestos sin recomputar, y tener
     * una sola fuente de verdad. La fija el hook saving() de Manifest al
     * crear (o si cambia la fecha operativa); aquí se backfillea el histórico.
     *
     * OJO: al desplegar, todo manifiesto histórico cuya ventana ya venció
     * queda CERRADO de inmediato — es el comportamiento acordado.
     */
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->timestamp('returns_deadline_at')->nullable()->index();
        });

        // ── Backfill del histórico (incluye soft-deleted) ─────────────
        // Volumen actual ~100s de manifiestos: chunk holgado, corre en ms.
        $dias = (int) config('api.devoluciones_ventana_dias_habiles', 5);

        DB::table('manifests')
            ->whereNull('returns_deadline_at')
            ->whereNotNull('date')
            ->orderBy('id')
            ->chunkById(200, function ($manifests) use ($dias) {
                foreach ($manifests as $manifest) {
                    DB::table('manifests')
                        ->where('id', $manifest->id)
                        ->update([
                            'returns_deadline_at' => BusinessDays::deadline($manifest->date, $dias),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropColumn('returns_deadline_at');
        });
    }
};
