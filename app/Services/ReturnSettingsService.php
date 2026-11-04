<?php

namespace App\Services;

use App\Enums\ReturnPublicationMode;
use App\Models\Manifest;
use App\Models\ReturnSetting;
use App\Models\User;
use App\Support\BusinessDays;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda la configuración de devoluciones (Configuración → Devoluciones).
 *
 * Qué afecta cada cambio:
 *   - Días de ventana: a los manifiestos que lleguen desde ahora (su cierre
 *     se fija al crearlos). Con $applyToOpenManifests también se recalcula
 *     el cierre de los que siguen ABIERTOS; los cerrados y los "sin límite"
 *     (NULL) no se tocan nunca: lo cerrado ya lo consumió Jaremar.
 *   - Cuándo ve Jaremar: a las devoluciones que se registren desde ahora
 *     (cada una guarda su published_at al registrarse).
 */
class ReturnSettingsService
{
    /**
     * @return int cuántos manifiestos abiertos cambiaron de fecha de cierre
     *
     * @throws ValidationException si un número sale de los topes
     */
    public function update(
        int $windowBusinessDays,
        ReturnPublicationMode $mode,
        int $delayDays,
        bool $applyToOpenManifests,
        User $user,
    ): int {
        if ($windowBusinessDays < 1 || $windowBusinessDays > ReturnSetting::MAX_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'window_business_days' => 'Los días para registrar devoluciones van de 1 a '.ReturnSetting::MAX_WINDOW_DAYS.'.',
            ]);
        }

        if ($delayDays < 0 || $delayDays > ReturnSetting::MAX_DELAY_DAYS) {
            throw ValidationException::withMessages([
                'publication_delay_days' => 'Los días antes de que Jaremar vea la devolución van de 0 a '.ReturnSetting::MAX_DELAY_DAYS.'.',
            ]);
        }

        return DB::transaction(function () use ($windowBusinessDays, $mode, $delayDays, $applyToOpenManifests, $user): int {
            // Dos administradores guardando a la vez quedan en orden.
            $setting = ReturnSetting::query()->lockForUpdate()->find(ReturnSetting::SINGLETON_ID)
                ?? new ReturnSetting;

            $setting->id = ReturnSetting::SINGLETON_ID;
            $setting->fill([
                'window_business_days' => $windowBusinessDays,
                'publication_mode' => $mode,
                'publication_delay_days' => $delayDays,
                'updated_by' => $user->id,
            ])->save();

            if (! $applyToOpenManifests) {
                return 0;
            }

            $updated = $this->applyWindowToOpenManifests($windowBusinessDays);

            activity()
                ->performedOn($setting)
                ->causedBy($user)
                ->withProperties(['dias_habiles' => $windowBusinessDays, 'manifiestos' => $updated])
                ->log("Ventana de devoluciones de {$windowBusinessDays} días hábiles aplicada a {$updated} manifiestos abiertos");

            return $updated;
        });
    }

    /**
     * Manifiestos con la ventana abierta en este momento (para avisar en la
     * pantalla cuántos tocaría "aplicar a los abiertos").
     */
    public function openManifestsCount(): int
    {
        return Manifest::query()
            ->whereNotNull('returns_deadline_at')
            ->where('returns_deadline_at', '>', now())
            ->count();
    }

    /**
     * Recalcula el cierre de cada manifiesto abierto con los días nuevos,
     * contando desde su llegada. Si los días bajan, el que ya pasó el nuevo
     * límite queda cerrado al guardar (y Jaremar lo recibe completo).
     *
     * UPDATE por query, sin eventos del modelo: el hook de Manifest solo
     * recalcula cuando cambia la fecha, y no hace falta una línea de
     * bitácora por manifiesto (queda una sola, en update()).
     */
    private function applyWindowToOpenManifests(int $windowBusinessDays): int
    {
        $updated = 0;

        Manifest::query()
            ->select(['id', 'date'])
            ->whereNotNull('returns_deadline_at')
            ->where('returns_deadline_at', '>', now())
            ->chunkById(200, function (Collection $manifests) use ($windowBusinessDays, &$updated): void {
                foreach ($manifests as $manifest) {
                    if ($manifest->date === null) {
                        continue;
                    }

                    Manifest::query()
                        ->whereKey($manifest->id)
                        ->update(['returns_deadline_at' => BusinessDays::deadline($manifest->date, $windowBusinessDays)]);

                    $updated++;
                }
            });

        return $updated;
    }
}
