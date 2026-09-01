<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Deposit;
use App\Models\Manifest;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Atribuye una bodega a los depósitos históricos.
 *
 * Los depósitos anteriores a la columna `deposits.warehouse_id` (27/08/2026)
 * no dicen a qué bodega pertenece la plata. Este comando la deduce con la
 * MISMA regla que usa el alta —DepositService::resolveWarehouseId— para que
 * el pasado y el presente se atribuyan igual:
 *
 *   1. Si el manifiesto tiene una sola bodega, es esa.
 *   2. Si el usuario que lo registró tiene exactamente una de las bodegas del
 *      manifiesto, es esa.
 *   3. Si no, se deja NULL.
 *
 * Lo que queda en NULL es deliberado, no una falla: un depósito registrado por
 * un usuario global en un manifiesto de dos bodegas es genuinamente ambiguo, y
 * adivinarlo metería un dato falso en un reporte financiero. Esos se resuelven
 * a mano editando el depósito, y mientras tanto el panel los declara.
 *
 * Idempotente: solo toca filas con warehouse_id NULL.
 *
 * Uso:
 *   php artisan deposits:backfill-warehouse --dry-run   # solo cuenta
 *   php artisan deposits:backfill-warehouse             # aplica
 */
class BackfillDepositWarehouse extends Command
{
    protected $signature = 'deposits:backfill-warehouse
                            {--chunk=200 : Depósitos por chunk}
                            {--dry-run : Contar sin escribir}';

    protected $description = 'Deduce la bodega de los depósitos históricos que no la tienen';

    public function handle(DepositService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $pendientes = Deposit::query()->whereNull('warehouse_id')->count();

        $this->info("Depósitos sin bodega: {$pendientes}".($dryRun ? ' (dry-run, no se escribirá nada)' : ''));

        if ($pendientes === 0) {
            return self::SUCCESS;
        }

        $atribuidos = 0;
        $ambiguos = 0;
        $montoAmbiguo = 0.0;
        $manifestIds = [];
        $usuarios = [];

        Deposit::query()
            ->whereNull('warehouse_id')
            ->with('manifest')
            ->chunkById($chunk, function ($deposits) use (
                $service, $dryRun, &$atribuidos, &$ambiguos, &$montoAmbiguo, &$manifestIds, &$usuarios
            ) {
                foreach ($deposits as $deposit) {
                    if ($deposit->manifest === null) {
                        $ambiguos++;
                        $montoAmbiguo += (float) $deposit->amount;

                        continue;
                    }

                    // Los usuarios se cachean: un mismo encargado registra
                    // cientos de depósitos y resolver sus bodegas una vez por
                    // fila sería una query por depósito.
                    $userId = $deposit->created_by;
                    if ($userId !== null && ! array_key_exists($userId, $usuarios)) {
                        $usuarios[$userId] = User::find($userId);
                    }

                    $warehouseId = $service->resolveWarehouseId(
                        $deposit->manifest,
                        $userId === null ? null : $usuarios[$userId]
                    );

                    if ($warehouseId === null) {
                        $ambiguos++;
                        $montoAmbiguo += (float) $deposit->amount;

                        continue;
                    }

                    if (! $dryRun) {
                        // Update directo sin Eloquent: es una deducción sobre
                        // datos históricos, no una decisión de negocio nueva.
                        // Pasar por el modelo generaría una entrada de
                        // ActivityLog por depósito y ensuciaría la bitácora con
                        // ruido de migración.
                        DB::table('deposits')
                            ->where('id', $deposit->id)
                            ->update(['warehouse_id' => $warehouseId]);
                    }

                    $manifestIds[$deposit->manifest_id] = $deposit->manifest_id;
                    $atribuidos++;
                }
            });

        $this->newLine();
        $this->info("Atribuidos: {$atribuidos}");

        if ($ambiguos > 0) {
            $this->warn("Sin atribuir: {$ambiguos} depósito(s) por L ".number_format($montoAmbiguo, 2).'.');
            $this->line('  Son los que no se pueden deducir sin adivinar: usuario global o multi-bodega');
            $this->line('  en un manifiesto de varias bodegas. Cuentan en el total del manifiesto pero no');
            $this->line('  en el desglose por bodega. Se corrigen editando el depósito desde el manifiesto.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry-run: no se escribió nada. Volvé a correrlo sin --dry-run para aplicarlo.');

            return self::SUCCESS;
        }

        // Los totales por bodega se recalculan al final, una vez por manifiesto
        // afectado, en vez de una vez por depósito.
        $this->newLine();
        $this->info('Recalculando totales de '.count($manifestIds).' manifiesto(s)...');

        foreach ($manifestIds as $manifestId) {
            Manifest::find($manifestId)?->recalculateTotals();
        }

        $this->info('Listo.');

        return self::SUCCESS;
    }
}
