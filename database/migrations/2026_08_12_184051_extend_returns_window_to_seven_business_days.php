<?php

use App\Support\BusinessDays;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const EXCEPCION_MANIFIESTO = '796078';

    private const EXCEPCION_DEADLINE = '2026-08-15 23:59:59';

    /**
     * Ventana de devoluciones: 5 → 7 días hábiles (decisión Mauricio,
     * 2026-08-12). Con 5 días las bodegas no alcanzaban a registrar todas
     * las devoluciones antes del congelamiento del manifiesto.
     *
     * El default nuevo (config/api.php) por sí solo NO mueve nada: cada
     * manifiesto persiste su returns_deadline_at al crearse, así que la
     * regla nueva solo aplicaría a los que nazcan después del deploy. Esta
     * migración alinea el stock existente con tres reglas explícitas:
     *
     *   1. ABIERTOS (returns_deadline_at > ahora) → recalculados a 7 días
     *      hábiles desde su llegada. Las bodegas ven el plazo nuevo de una
     *      vez, sin una semana mixta de 5 y 7.
     *
     *   2. CERRADOS (returns_deadline_at <= ahora) → NO SE TOCAN. Su
     *      paquete ya se publicó a Jaremar como completo e inmutable
     *      (contrato de Isack, punto 2). Reabrirlos en masa dejaría
     *      devoluciones nuevas colgando de fechas de emisión que Jaremar
     *      probablemente ya no vuelve a consultar: devoluciones perdidas.
     *
     *   3. SIN LÍMITE (NULL, transición 2026-07-21) → NO SE TOCAN. El NULL
     *      es deliberado y nunca se rellena solo (ver hooks de Manifest).
     *
     * EXCEPCIÓN PUNTUAL — manifiesto 796078 (llegó el 06/08, cerró el
     * 11/08 con 0 devoluciones sobre 17 facturas): la bodega no alcanzó a
     * registrar sus devoluciones antes del cierre. Se reabre con plazo FIJO
     * hasta el sábado 2026-08-15 11:59:59 pm (hora Honduras), no con el
     * cálculo de 7 hábiles, que ya no lo alcanzaría. Al vencer se congela
     * solo y su paquete completo sale en el siguiente lote que consulte
     * Jaremar — que debe volver a consultar las fechas de EMISIÓN de sus
     * facturas para verlo (aviso a Isack).
     *
     * Idempotente: recalcular sobre un deadline ya de 7 días da el mismo
     * valor, y la excepción es una asignación fija.
     *
     * Sin down() destructivo: volver a 5 días es un cambio de config más un
     * backfill inverso deliberado, no un rollback automático que le quite
     * plazo a bodegas que ya lo están usando.
     */
    public function up(): void
    {
        $dias = max(1, (int) config('api.devoluciones_ventana_dias_habiles', 7));
        $tz = config('manifests.dates.timezone', 'America/Tegucigalpa');
        $ahora = now();

        // ── 1. Manifiestos con la ventana todavía ABIERTA ─────────────
        // Volumen: ~100s de filas, chunk holgado. chunkById pagina por id,
        // así que extender el deadline (que sigue cumpliendo el WHERE) no
        // reprocesa filas ni cicla.
        $extendidos = 0;

        DB::table('manifests')
            ->whereNotNull('date')
            ->whereNotNull('returns_deadline_at')
            ->where('returns_deadline_at', '>', $ahora)
            ->orderBy('id')
            ->chunkById(200, function ($manifests) use ($dias, &$extendidos) {
                foreach ($manifests as $manifest) {
                    DB::table('manifests')
                        ->where('id', $manifest->id)
                        ->update([
                            'returns_deadline_at' => BusinessDays::deadline($manifest->date, $dias),
                        ]);

                    $extendidos++;
                }
            });

        // ── 2. Excepción: reapertura puntual con plazo fijo ───────────
        $reabiertos = DB::table('manifests')
            ->where('number', self::EXCEPCION_MANIFIESTO)
            ->update([
                'returns_deadline_at' => Carbon::parse(self::EXCEPCION_DEADLINE, $tz),
            ]);

        Log::info('Backfill de la ventana de devoluciones aplicado', [
            'ventana_dias_habiles' => $dias,
            'manifiestos_extendidos' => $extendidos,
            'excepcion_manifiesto' => self::EXCEPCION_MANIFIESTO,
            'excepcion_reabierta' => $reabiertos > 0,
            'excepcion_deadline' => self::EXCEPCION_DEADLINE,
        ]);

        if ($reabiertos === 0) {
            // Esperado en local/testing (el manifiesto no existe ahí). En
            // producción significa que el número cambió: revisar a mano.
            Log::warning('Reapertura puntual sin efecto: manifiesto no encontrado', [
                'manifiesto' => self::EXCEPCION_MANIFIESTO,
            ]);
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: ver el docblock de up().
    }
};
