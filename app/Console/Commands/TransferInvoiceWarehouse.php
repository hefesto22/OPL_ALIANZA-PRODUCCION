<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceWarehouseTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Traslada facturas de una bodega a otra.
 *
 * Puerta de consola del InvoiceWarehouseTransferService — la misma que va a
 * usar la acción del panel. Existe porque la corrección tiene que poder
 * hacerse el mismo día en que Jaremar manda el `Almacen` equivocado, sin
 * esperar un deploy de UI.
 *
 * Uso:
 * El responsable (--user) es obligatorio incluso en dry-run: es quien tiene
 * que cargar con el permiso TransferWarehouse:Invoice y quien queda firmando
 * el movimiento en la bitácora. Correr esto como root del servidor no salta
 * ese control.
 *
 *   # 1. Siempre primero: ver el plan sin escribir nada.
 *   php artisan invoices:transfer-warehouse \
 *       --invoice=002-001-01-03949657 \
 *       --invoice=002-001-01-03949658 \
 *       --to=OAS --user=mayra@hozana.cloud --dry-run
 *
 *   # 2. Ejecutar (pide confirmación mostrando el monto que se mueve).
 *   php artisan invoices:transfer-warehouse \
 *       --invoice=002-001-01-03949657 \
 *       --to=OAS \
 *       --reason="Jaremar facturó a OAI por error; la entrega es de Santa Bárbara" \
 *       --user=mayra@hozana.cloud
 */
class TransferInvoiceWarehouse extends Command
{
    protected $signature = 'invoices:transfer-warehouse
                            {--invoice=* : Número de factura a trasladar (repetible)}
                            {--to= : Código de la bodega destino, ej. OAS}
                            {--reason= : Motivo operativo — queda en la bitácora}
                            {--user= : ID o email del responsable — obligatorio, requiere el permiso TransferWarehouse:Invoice}
                            {--dry-run : Mostrar el plan sin escribir nada}';

    protected $description = 'Traslada facturas de una bodega a otra, arrastrando devoluciones y recalculando totales';

    public function handle(InvoiceWarehouseTransferService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $targetCode = trim((string) $this->option('to'));
        if ($targetCode === '') {
            $this->error('Falta --to con el código de la bodega destino (ej. --to=OAS).');

            return self::FAILURE;
        }

        $target = Warehouse::query()->where('code', $targetCode)->first();
        if ($target === null) {
            $this->error("No existe la bodega con código '{$targetCode}'.");
            $this->line('Bodegas registradas: '.Warehouse::query()->pluck('code')->implode(', '));

            return self::FAILURE;
        }

        $numbers = array_values(array_filter(array_map('trim', (array) $this->option('invoice'))));
        if ($numbers === []) {
            $this->error('Falta --invoice con al menos un número de factura.');

            return self::FAILURE;
        }

        // El responsable se resuelve y se autoriza ANTES de armar el plan:
        // así el dry-run tampoco le sirve un preview a quien no puede ejecutar.
        $causer = $this->resolveCauser();
        if ($causer === null) {
            return self::FAILURE;
        }

        if (! $causer->can(InvoiceWarehouseTransferService::PERMISSION)) {
            $this->error("El usuario {$causer->email} no tiene el permiso "
                .InvoiceWarehouseTransferService::PERMISSION.'.');
            $this->line('Ese permiso lo ejerce el super_admin. Se asigna desde '
                .'Filament Shield → Roles → Permisos personalizados.');

            return self::FAILURE;
        }

        $invoices = $this->resolveInvoices($numbers);
        if ($invoices === null) {
            return self::FAILURE;
        }

        $plan = $service->plan($invoices, $target, $causer);

        $this->renderPlan($plan, $target, $dryRun);

        if ($plan['blockers'] !== []) {
            return self::FAILURE;
        }

        if ($plan['movements'] === []) {
            $this->info('No hay nada que trasladar.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry-run: no se escribió nada. Volvé a correrlo sin --dry-run para aplicarlo.');

            return self::SUCCESS;
        }

        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < InvoiceWarehouseTransferService::MIN_REASON_LENGTH) {
            $this->error('Falta --reason con un motivo de al menos '
                .InvoiceWarehouseTransferService::MIN_REASON_LENGTH
                .' caracteres. Es lo único que va a explicar este movimiento en una auditoría.');

            return self::FAILURE;
        }

        $monto = number_format($plan['total_amount'], 2);
        $cuantas = count($plan['movements']);

        if (! $this->confirm("¿Trasladar {$cuantas} factura(s) por L {$monto} a {$target->code}?", false)) {
            $this->comment('Cancelado. No se escribió nada.');

            return self::SUCCESS;
        }

        try {
            $result = $service->transfer($invoices, $target, $reason, $causer);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Listo: {$result['invoices']} factura(s) y {$result['returns']} devolución(es) "
            ."trasladadas a {$target->code}; {$result['manifests']} manifiesto(s) recalculado(s).");
        $this->line('El movimiento quedó en Registros de Actividad con el motivo y los números de factura.');

        return self::SUCCESS;
    }

    /**
     * Resuelve números de factura a modelos.
     *
     * Acepta el número completo ('002-001-01-03949657') y, como cortesía al
     * operador que copia solo el correlativo, también un sufijo — siempre que
     * identifique una sola factura. Si un sufijo es ambiguo, aborta y muestra
     * los candidatos: adivinar cuál era sería mover plata a ciegas.
     *
     * @param  array<int, string>  $numbers
     * @return Collection<int, Invoice>|null null si algo no resolvió
     */
    private function resolveInvoices(array $numbers): ?Collection
    {
        $found = Invoice::query()
            ->whereIn('invoice_number', $numbers)
            ->get()
            ->keyBy('invoice_number');

        $missing = array_values(array_diff($numbers, $found->keys()->all()));
        $resolved = $found->values();
        $failed = [];

        foreach ($missing as $number) {
            $candidates = Invoice::query()
                ->where('invoice_number', 'like', '%'.$number)
                ->limit(5)
                ->get();

            if ($candidates->count() === 1) {
                $match = $candidates->first();
                $this->comment("'{$number}' resolvió a la factura {$match->invoice_number}.");
                $resolved->push($match);

                continue;
            }

            if ($candidates->count() > 1) {
                $this->error("'{$number}' coincide con varias facturas: "
                    .$candidates->pluck('invoice_number')->implode(', '));
                $failed[] = $number;

                continue;
            }

            $this->error("No existe ninguna factura con número '{$number}'.");
            $failed[] = $number;
        }

        return $failed === [] ? $resolved : null;
    }

    /**
     * Responsable del traslado. Obligatorio: no hay camino anónimo.
     *
     * @return User|null null = falta el dato o el usuario no existe (el error
     *                   ya se reportó por pantalla)
     */
    private function resolveCauser(): ?User
    {
        $ref = trim((string) $this->option('user'));

        if ($ref === '') {
            $this->error('Falta --user con el id o email del responsable del traslado.');
            $this->line('Mover facturas entre bodegas exige un responsable con el permiso '
                .InvoiceWarehouseTransferService::PERMISSION.'; nadie mueve plata de forma anónima.');

            return null;
        }

        $user = User::query()
            ->when(is_numeric($ref), fn ($q) => $q->orWhere('id', (int) $ref))
            ->orWhere('email', $ref)
            ->first();

        if ($user === null) {
            $this->error("No existe usuario con id o email '{$ref}'.");
        }

        return $user;
    }

    /**
     * @param  array{movements:array<int, array<string, mixed>>, already_there:array<int, string>,
     *               blockers:array<int, string>, total_amount:float, manifest_ids:array<int, int>}  $plan
     */
    private function renderPlan(array $plan, Warehouse $target, bool $dryRun): void
    {
        $this->newLine();
        $this->line('Bodega destino: <options=bold>'.$target->code.'</> — '.$target->name);

        if ($plan['already_there'] !== []) {
            $this->comment('Ya estaban en '.$target->code.' (se ignoran): '
                .implode(', ', $plan['already_there']));
        }

        if ($plan['movements'] !== []) {
            $this->newLine();
            $this->table(
                ['Factura', 'Manifiesto', 'Bodega actual', 'Destino', 'Total L', 'Devoluciones'],
                array_map(fn (array $m): array => [
                    $m['invoice_number'],
                    '#'.$m['manifest_number'],
                    $m['from_code'],
                    $target->code,
                    number_format($m['total'], 2),
                    $m['returns'] > 0 ? $m['returns'].' (viajan con la factura)' : '0',
                ], $plan['movements'])
            );

            $this->line('Total que cambia de bodega: <options=bold>L '
                .number_format($plan['total_amount'], 2).'</>');
        }

        foreach ($plan['blockers'] as $blocker) {
            $this->error($blocker);
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('MODO DRY-RUN — nada de esto se escribió todavía.');
        }
    }
}
