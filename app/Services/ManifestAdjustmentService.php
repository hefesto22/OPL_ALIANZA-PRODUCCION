<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Manifest;
use App\Models\ManifestAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajuste manual de centavos sobre un manifiesto.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  QUÉ RESUELVE
 * ─────────────────────────────────────────────────────────────────────
 *  Un manifiesto puede quedar con una diferencia de centavos que ningún
 *  depósito va a cubrir: el banco redondeó al transferir, o el depósito entró
 *  con un centavo de más cuando el validador viejo aún lo dejaba pasar sin
 *  pedir explicación. Como isReadyToClose() exige cero exacto en los
 *  faltantes, esos manifiestos quedaban varados de por vida — al 28/07/2026
 *  había 13 así en producción.
 *
 *  Este servicio es la única vía para llevar esa diferencia a cero, y deja
 *  constancia de quién lo hizo y por qué.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ UN REGISTRO Y NO UNA TOLERANCIA AUTOMÁTICA
 * ─────────────────────────────────────────────────────────────────────
 *  La alternativa evaluada era dejar cerrar cuando |difference| <= 0.05.
 *  Se descartó: un cierre automático no responde "¿quién autorizó dar por
 *  buenos esos centavos?". Acá cada ajuste lo firma un usuario con permiso
 *  Adjust:Manifest, con motivo obligatorio y entrada en el canal `finance`.
 *  isReadyToClose() no se tocó — sigue exigiendo cero exacto.
 */
class ManifestAdjustmentService
{
    /**
     * Registra un ajuste y recalcula los totales del manifiesto.
     *
     * @param  float  $amount  Positivo da por recibido un faltante; negativo
     *                         da por bueno un sobrante. Normalmente es
     *                         exactamente la `difference` actual, lo que deja
     *                         el manifiesto en cero y listo para cerrar.
     */
    public function adjust(Manifest $manifest, float $amount, string $reason, int $userId): ManifestAdjustment
    {
        $amount = round($amount, 2);
        $reason = trim($reason);

        $this->assertValidAmount($amount);
        $this->assertValidReason($reason);

        return DB::transaction(function () use ($manifest, $amount, $reason, $userId) {
            // Lock pesimista: el ajuste se calcula contra la diferencia actual.
            // Sin lock, un depósito concurrente podría cambiarla entre que el
            // operador la ve en pantalla y el ajuste se graba — dejando el
            // manifiesto descuadrado en el sentido contrario.
            $locked = Manifest::query()
                ->whereKey($manifest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isClosed()) {
                throw ValidationException::withMessages([
                    'amount' => "El manifiesto #{$locked->number} está cerrado y no admite ajustes.",
                ]);
            }

            $before = (float) $locked->difference;

            $adjustment = ManifestAdjustment::create([
                'manifest_id' => $locked->id,
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $userId,
            ]);

            $locked->recalculateTotals();
            $locked->refresh();

            activity('finance')
                ->performedOn($locked)
                ->causedBy(auth()->user())
                ->withProperties([
                    'manifest_number' => $locked->number,
                    'monto_ajuste' => $amount,
                    'motivo' => $reason,
                    'diferencia_antes' => $before,
                    'diferencia_despues' => (float) $locked->difference,
                    'total_a_depositar' => (float) $locked->total_to_deposit,
                    'total_depositado' => (float) $locked->total_deposited,
                ])
                ->log('Ajuste de diferencia registrado');

            return $adjustment;
        });
    }

    /**
     * Tope absoluto de un ajuste individual, en lempiras.
     *
     * Vive en config y no en la BD para poder subirlo sin migración si la
     * operación lo justifica. Arranca en 1.00: la regla de negocio es
     * "centavos de redondeo", no "cuadrar manifiestos a mano".
     */
    public function maxAmount(): float
    {
        return (float) config('manifests.ajustes.tope_hnl', 1.00);
    }

    private function assertValidAmount(float $amount): void
    {
        if ($amount === 0.0) {
            throw ValidationException::withMessages([
                'amount' => 'El ajuste no puede ser de cero.',
            ]);
        }

        if (abs($amount) > $this->maxAmount()) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'El ajuste (HNL %s) supera el tope permitido de HNL %s. '.
                    'Una diferencia de ese tamaño no es un redondeo: revisá los depósitos y devoluciones del manifiesto.',
                    number_format($amount, 2),
                    number_format($this->maxAmount(), 2)
                ),
            ]);
        }
    }

    private function assertValidReason(string $reason): void
    {
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo del ajuste debe tener al menos 10 caracteres.',
            ]);
        }
    }
}
