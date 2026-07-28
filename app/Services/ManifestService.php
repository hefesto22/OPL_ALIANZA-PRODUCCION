<?php

namespace App\Services;

use App\Models\Manifest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Servicio del ciclo de vida de un Manifest.
 *
 * Centraliza las operaciones de cierre y reapertura — antes vivían en el
 * modelo (Manifest::close / Manifest::reopen). Moverlas acá:
 *   1. Saca lógica de negocio del modelo (que solo debe ser data + relaciones).
 *   2. Permite agregar guards de pre-condición server-side. La UI ya filtra
 *      con isReadyToClose() antes de mostrar el botón, pero esta es la
 *      última línea de defensa contra requests directos, CLI o API futura.
 *   3. Habilita inyección por DI y mockeo en tests específicos.
 *
 * Los predicados de consulta (isClosed, isReadyToClose) se mantienen en el
 * modelo porque son lecturas puras sobre el propio estado — no son lógica
 * de negocio mutable.
 */
class ManifestService
{
    /**
     * Cierra un manifiesto. Pre-condiciones validadas server-side:
     *   - El manifest NO está cerrado ya.
     *   - difference == 0 (totales cuadran).
     *   - total_to_deposit > 0 (no es manifiesto vacío).
     *   - No tiene devoluciones pendientes (status='pending').
     *
     * @throws RuntimeException si alguna pre-condición no se cumple.
     */
    public function closeManifest(Manifest $manifest, int $userId): void
    {
        if (! $manifest->isReadyToClose()) {
            // Mensaje específico según qué impide el cierre — útil para
            // debugging y para mostrar al admin si llega vía request directa.
            if ($manifest->isClosed()) {
                throw new RuntimeException(
                    "El manifiesto #{$manifest->number} ya está cerrado."
                );
            }

            if (round((float) $manifest->difference, 2) > 0) {
                throw new RuntimeException(
                    "El manifiesto #{$manifest->number} no se puede cerrar: ".
                    'faltan HNL '.number_format((float) $manifest->difference, 2).
                    ' por depositar.'
                );
            }

            // Sobrepago sin justificación: no debería ocurrir (DepositService la
            // exige al registrar), pero si un dato heredado o una carga manual
            // lo produjo, no se cierra a ciegas.
            if ($manifest->isOverpaid() && ! $manifest->hasJustifiedOverpayment()) {
                throw new RuntimeException(
                    "El manifiesto #{$manifest->number} está sobre-depositado por HNL ".
                    number_format($manifest->overpaidAmount(), 2).
                    ' y ninguno de sus depósitos tiene justificación registrada. '.
                    'Revisá el depósito que generó el exceso antes de cerrarlo.'
                );
            }

            if ((float) $manifest->total_to_deposit <= 0) {
                throw new RuntimeException(
                    "El manifiesto #{$manifest->number} no tiene importes a depositar."
                );
            }

            if ($manifest->returns()->where('status', 'pending')->exists()) {
                throw new RuntimeException(
                    "El manifiesto #{$manifest->number} tiene devoluciones pendientes ".
                    'de revisión. Resuelve todas antes de cerrar.'
                );
            }

            // Catch-all — no debería llegar acá, pero por defensa.
            throw new RuntimeException(
                "El manifiesto #{$manifest->number} no está listo para cerrar."
            );
        }

        $sobrepago = $manifest->overpaidAmount();

        DB::transaction(function () use ($manifest, $userId, $sobrepago) {
            $manifest->update([
                'status' => 'closed',
                'closed_by' => $userId,
                'closed_at' => now(),
            ]);

            // Un cierre con sobrepago es un evento de negocio, no un cierre
            // más: alguien dio por bueno que la empresa recibió plata de más.
            // Queda en el canal `finance` con el monto y la justificación que
            // lo habilitó, para poder responderlo meses después.
            if ($sobrepago > 0) {
                activity('finance')
                    ->performedOn($manifest)
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'manifest_number' => $manifest->number,
                        'sobrepago' => $sobrepago,
                        'total_a_depositar' => (float) $manifest->total_to_deposit,
                        'total_depositado' => (float) $manifest->total_deposited,
                        'justificaciones' => $manifest->deposits()
                            ->active()
                            ->whereNotNull('justification')
                            ->pluck('justification')
                            ->all(),
                    ])
                    ->log('Manifiesto cerrado con sobrepago');
            }
        });
    }

    /**
     * Reabre un manifiesto cerrado. Pre-condición:
     *   - El manifest ESTÁ cerrado actualmente.
     *
     * @throws RuntimeException si el manifest no está cerrado.
     */
    public function reopenManifest(Manifest $manifest): void
    {
        if (! $manifest->isClosed()) {
            throw new RuntimeException(
                "El manifiesto #{$manifest->number} no está cerrado y no puede reabrirse."
            );
        }

        DB::transaction(function () use ($manifest) {
            $manifest->update([
                'status' => 'imported',
                'closed_by' => null,
                'closed_at' => null,
            ]);
        });
    }
}
