<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DepositService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Casos de prueba para el sobrepago y el ajuste de centavos.
 *
 * PENSADO PARA pruebas.hozana.cloud — NO correr en producción. Usa el rango
 * 95xxxx, que no colisiona con la numeración real de Jaremar (78xxxx) ni con
 * la del DemoDataSeeder (90xxxx).
 *
 * ─────────────────────────────────────────────────────────────────────
 *  QUÉ SE PRUEBA
 * ─────────────────────────────────────────────────────────────────────
 *  Un depósito se aplica ÍNTEGRO al manifiesto donde se registra. Puede
 *  superar el total: en ese caso el manifiesto queda sobrepagado, se exige
 *  justificación, y aun así se puede cerrar. Nada se mueve solo a otro
 *  manifiesto.
 *
 *   #       Diferencia   Rol en la prueba
 *   ─────────────────────────────────────────────────────────────────
 *   950001    +0.32      faltan centavos → se cierra con AJUSTE positivo
 *   950002    −0.01      sobran centavos SIN justificación (dato heredado)
 *                        → solo se cierra con AJUSTE negativo
 *   950003  +800.00      manifiesto de trabajo → probar el SOBREPAGO acá
 *   950004    0.00       CERRADO — control de que no admite cambios
 */
class DepositCasesSeeder extends Seeder
{
    /**
     * Bases de datos donde este seeder NUNCA debe correr.
     *
     * Se identifica producción por el nombre de la base y no por APP_ENV
     * porque pruebas.hozana.cloud también corre con APP_ENV=production.
     *
     * @var array<int, string>
     */
    private const PRODUCTION_DATABASES = ['distribuidora_hozana'];

    /**
     * Números que el reset debe limpiar.
     *
     * Incluye casos de versiones anteriores del seeder (950005–950011, del
     * modelo de reparto multi-manifiesto que se descartó). Si no estuvieran
     * acá quedarían huérfanos en la base de pruebas para siempre.
     *
     * @var array<int, string>
     */
    private const PURGE_NUMBERS = [
        '950001', '950002', '950003', '950004', '950005', '950006',
        '950007', '950008', '950009', '950010', '950011',
    ];

    /**
     * @var array<int, array{number: string, days: int, warehouse: string, total: float, deposit: float|null, legacy?: bool, closed?: bool, nota: string}>
     */
    private const CASES = [
        [
            'number' => '950001', 'days' => 20, 'warehouse' => 'OAC',
            'total' => 1000.00, 'deposit' => 999.68,
            'nota' => 'faltan 0.32 — se cierra con ajuste positivo',
        ],
        [
            'number' => '950002', 'days' => 15, 'warehouse' => 'OAC',
            'total' => 500.00, 'deposit' => 500.01, 'legacy' => true,
            'nota' => 'sobran 0.01 SIN justificación (heredado) — solo con ajuste',
        ],
        [
            'number' => '950003', 'days' => 0, 'warehouse' => 'OAC',
            'total' => 800.00, 'deposit' => null,
            'nota' => 'manifiesto de trabajo — registrar acá el sobrepago',
        ],
        [
            'number' => '950004', 'days' => 12, 'warehouse' => 'OAS',
            'total' => 300.00, 'deposit' => 300.00, 'closed' => true,
            'nota' => 'CERRADO — no admite depósitos ni ajustes',
        ],
    ];

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (in_array($database, self::PRODUCTION_DATABASES, true)) {
            $this->command?->error(
                "DepositCasesSeeder ABORTADO: la conexión apunta a la base de producción ({$database})."
            );

            return;
        }

        $supplier = Supplier::query()->first();
        $warehouses = Warehouse::whereIn('code', ['OAC', 'OAS'])->get()->keyBy('code');
        $user = User::query()->orderBy('id')->first();

        if (! $supplier || $warehouses->count() < 2 || ! $user) {
            $this->command?->error('Faltan proveedor, bodegas OAC/OAS o usuarios. Corré los seeders base primero.');

            return;
        }

        $this->command?->info("Sembrando casos de prueba en la base: {$database}");

        // DEPOSIT_CASES_RESET=1 borra los casos y los vuelve a sembrar desde
        // cero. Necesario para repetir la demo: una vez ajustados o cerrados,
        // los manifiestos ya no muestran nada.
        if (env('DEPOSIT_CASES_RESET')) {
            $this->purge();
        }

        // El DepositService registra actividad con auth()->user(); sin sesión
        // los logs quedarían sin causer.
        Auth::login($user);

        $service = app(DepositService::class);

        foreach (self::CASES as $case) {
            $manifest = $this->manifest($case, $supplier->id, $warehouses[$case['warehouse']]);

            if (! $manifest) {
                continue;
            }

            if ($case['deposit'] !== null && $manifest->deposits()->count() === 0) {
                if ($case['legacy'] ?? false) {
                    $this->seedLegacyOverDeposit($manifest, $case, $user->id);
                } else {
                    $service->createDeposit($manifest, $this->depositData($case), $user->id);
                }

                $manifest->refresh();
            }

            if (($case['closed'] ?? false) && ! $manifest->isClosed()) {
                $manifest->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => $user->id,
                ]);
            }
        }

        Auth::logout();

        $this->summary();
    }

    /**
     * Crea el manifiesto con una factura, o devuelve el existente sin tocarlo.
     *
     * @param  array{number: string, days: int, total: float}  $case
     */
    private function manifest(array $case, int $supplierId, Warehouse $warehouse): ?Manifest
    {
        $existing = Manifest::where('number', $case['number'])->first();

        if ($existing) {
            $this->command?->line("  #{$case['number']} ya existe — se deja como está.");

            return $existing;
        }

        $date = now()->subDays($case['days'])->toDateString();

        $manifest = Manifest::create([
            'supplier_id' => $supplierId,
            // warehouse_id NULL a propósito: replica cómo entran los
            // manifiestos por la API de Jaremar (la bodega vive en las
            // facturas).
            'warehouse_id' => null,
            'number' => $case['number'],
            'date' => $date,
            'status' => 'imported',
        ]);

        Invoice::create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'imported',
            'invoice_number' => 'PRB'.$case['number'],
            'jaremar_id' => (int) $case['number'],
            'invoice_date' => $date,
            'due_date' => $date,
            'seller_id' => 'VEN01',
            'seller_name' => 'Vendedor de Pruebas',
            'client_id' => 'CLI'.$case['number'],
            'client_name' => 'Cliente de Pruebas '.$case['number'],
            'client_rtn' => '08019000000000',
            'deliver_to' => 'Cliente de Pruebas',
            'department' => $warehouse->department ?? 'Copán',
            'municipality' => 'Santa Rosa de Copán',
            'address' => 'Barrio Centro',
            'route_number' => '230',
            'payment_type' => 'CONTADO',
            'credit_days' => 0,
            'invoice_type' => 'FAC',
            'cai' => '2F0037-619ACD-2A66E0-63BE03-0909DC-56',
            'range_start' => '002-001-01-04000001',
            'range_end' => '002-001-01-04999999',
            'total' => $case['total'],
            'isv15' => 0,
            'isv18' => 0,
        ]);

        $manifest->recalculateTotals();

        return $manifest->refresh();
    }

    /**
     * Siembra un manifiesto SOBRE-DEPOSITADO y SIN justificación, escribiendo
     * el depósito directo en la base.
     *
     * No se puede usar DepositService: exige justificación en cuanto el monto
     * supera el pendiente, justamente para que este estado no vuelva a
     * generarse. Los manifiestos así que hay en producción vienen del
     * validador viejo, que tenía un margen de tolerancia de un centavo y lo
     * dejaba pasar sin pedir nada. Sin justificación, el cierre está bloqueado
     * y solo el ajuste puede desatascarlos: eso es lo que este caso permite
     * probar.
     *
     * @param  array{number: string, days: int, deposit: float|null}  $case
     */
    private function seedLegacyOverDeposit(Manifest $manifest, array $case, int $userId): void
    {
        Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => $case['deposit'],
            'deposit_date' => now()->subDays(max(0, $case['days'] - 1))->toDateString(),
            'bank' => 'BAC',
            'reference' => 'PRB-'.$case['number'],
            'observations' => 'Depósito HEREDADO simulado (código anterior a la justificación obligatoria).',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $manifest->recalculateTotals();
        $manifest->refresh();

        $this->command?->line(
            "  #{$case['number']} sembrado como sobre-depositado sin justificación (diferencia ".
            number_format((float) $manifest->difference, 2).')'
        );
    }

    /**
     * @param  array{number: string, days: int, deposit: float|null}  $case
     * @return array<string, mixed>
     */
    private function depositData(array $case): array
    {
        return [
            'amount' => $case['deposit'],
            'deposit_date' => now()->subDays(max(0, $case['days'] - 1))->toDateString(),
            'bank' => 'BAC',
            'reference' => 'PRB-'.$case['number'],
            'observations' => 'Depósito sembrado por DepositCasesSeeder.',
        ];
    }

    /**
     * Borra los casos de prueba para poder re-sembrarlos.
     *
     * Solo se activa con DEPOSIT_CASES_RESET=1. Limpia también los números de
     * versiones anteriores del seeder (ver PURGE_NUMBERS).
     */
    private function purge(): void
    {
        $manifiestos = Manifest::whereIn('number', self::PURGE_NUMBERS)->get();

        if ($manifiestos->isEmpty()) {
            return;
        }

        foreach ($manifiestos as $manifiesto) {
            foreach ($manifiesto->deposits()->withTrashed()->get() as $deposito) {
                $deposito->forceDelete();
            }

            $manifiesto->adjustments()->delete();

            foreach ($manifiesto->invoices()->get() as $factura) {
                $factura->lines()->delete();
                $factura->forceDelete();
            }

            $manifiesto->warehouseTotals()->delete();
            $manifiesto->forceDelete();
        }

        $this->command?->warn(
            'Casos de prueba borrados ('.$manifiestos->count().'). Se vuelven a sembrar desde cero.'
        );
    }

    private function summary(): void
    {
        $rows = Manifest::whereIn('number', array_column(self::CASES, 'number'))
            ->orderBy('number')
            ->get(['number', 'date', 'total_to_deposit', 'total_deposited', 'adjustment_amount', 'difference', 'status']);

        $this->command?->newLine();
        $this->command?->info('Casos de prueba listos:');

        $this->command?->table(
            ['#', 'Fecha', 'A depositar', 'Depositado', 'Ajuste', 'Diferencia', 'Estado'],
            $rows->map(fn ($m) => [
                $m->number,
                $m->date?->format('d/m/Y'),
                number_format((float) $m->total_to_deposit, 2),
                number_format((float) $m->total_deposited, 2),
                number_format((float) $m->adjustment_amount, 2),
                number_format((float) $m->difference, 2),
                $m->status,
            ])->all()
        );

        $this->command?->info('Tope del ajuste: HNL '.number_format((float) config('manifests.ajustes.tope_hnl'), 2));
        $this->command?->info('→ Depositar HNL 850.00 en el #950003 debe dejarlo con sobrepago de HNL 50.00 y cerrable.');
    }
}
