<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Deposit;
use App\Models\DepositAllocation;
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
 * Casos de prueba para la aplicación multi-manifiesto y el ajuste de centavos.
 *
 * PENSADO PARA pruebas.hozana.cloud — NO correr en producción. Crea
 * manifiestos en el rango 95xxxx, que no colisiona con la numeración real de
 * Jaremar (78xxxx) ni con la del DemoDataSeeder (90xxxx).
 *
 * Idempotente: si un manifiesto ya existe, se deja intacto. Para regenerar,
 * borrarlos primero desde el panel.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  EL ESCENARIO
 * ─────────────────────────────────────────────────────────────────────
 *  Los manifiestos están fechados a propósito para que el reparto FIFO
 *  (del más antiguo al más nuevo) sea verificable a mano:
 *
 *   #       Bodega      Días  Total      Depositado  Diferencia   Rol en la prueba
 *   ─────────────────────────────────────────────────────────────────────────────
 *   950005  OAC          28   1,000.00     999.50      +0.50      candidato FIFO 1º
 *   950006  OAC          25     500.00     499.75      +0.25      candidato FIFO 2º
 *   950001  OAC          20   1,000.00     999.68      +0.32      candidato FIFO 3º
 *   950002  OAC          15     500.00     500.01      −0.01      solo se cierra con AJUSTE
 *   950008  OAC          12     300.00     300.00       0.00      CERRADO — nunca recibe
 *   950009  OAC+OAS       8     700.00       0.00    +700.00      multi-bodega
 *   950010  OAC           5   1,000.00     995.00      +5.00      supera el tope de ajuste
 *   950004  OAS          10     300.00       0.00    +300.00      aislamiento entre bodegas
 *   950003  OAC           0     800.00       0.00    +800.00      manifiesto de trabajo
 *   950007  OAC           0     200.00       0.00    +200.00      sobredepósito extremo
 *
 *  Los centavos pendientes de OAC suman exactamente 0.50 + 0.25 + 0.32 = 1.07,
 *  así que un depósito de 801.07 registrado desde el 950003 debe dejar CUATRO
 *  manifiestos en cero de un solo golpe. Ese es el número mágico de la demo.
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
     * Definición declarativa de los casos.
     *
     * lines:   [código de bodega => monto facturado]
     * deposit: monto a depositar (null = sin depósito)
     * closed:  si queda cerrado tras cuadrar
     *
     * @var array<int, array{number: string, days: int, lines: array<string, float>, deposit: float|null, closed?: bool, nota: string}>
     */
    private const CASES = [
        [
            'number' => '950005', 'days' => 28,
            'lines' => ['OAC' => 1000.00], 'deposit' => 999.50,
            'nota' => 'falta 0.50 — primer candidato del reparto FIFO',
        ],
        [
            'number' => '950006', 'days' => 25,
            'lines' => ['OAC' => 500.00], 'deposit' => 499.75,
            'nota' => 'falta 0.25 — segundo candidato FIFO',
        ],
        [
            'number' => '950001', 'days' => 20,
            'lines' => ['OAC' => 1000.00], 'deposit' => 999.68,
            'nota' => 'falta 0.32 — tercer candidato FIFO',
        ],
        [
            'number' => '950002', 'days' => 15,
            'lines' => ['OAC' => 500.00], 'deposit' => 500.01, 'legacy' => true,
            'nota' => 'SOBRA 0.01 — dato HEREDADO: el código nuevo ya no puede generarlo',
        ],
        [
            'number' => '950008', 'days' => 12,
            'lines' => ['OAC' => 300.00], 'deposit' => 300.00, 'closed' => true,
            'nota' => 'CERRADO — jamás debe recibir dinero del reparto',
        ],
        [
            'number' => '950004', 'days' => 10,
            'lines' => ['OAS' => 300.00], 'deposit' => null,
            'nota' => 'bodega OAS pura — control de aislamiento entre bodegas',
        ],
        [
            'number' => '950009', 'days' => 8,
            'lines' => ['OAC' => 400.00, 'OAS' => 300.00], 'deposit' => null,
            'nota' => 'MULTI-BODEGA (OAC+OAS) — participa del reparto de ambas',
        ],
        [
            'number' => '950010', 'days' => 5,
            'lines' => ['OAC' => 1000.00], 'deposit' => 995.00,
            'nota' => 'falta 5.00 — supera el tope de ajuste, solo se arregla con dinero',
        ],
        [
            'number' => '950003', 'days' => 0,
            'lines' => ['OAC' => 800.00], 'deposit' => null,
            'nota' => 'manifiesto de trabajo — registrar acá el depósito de 801.07',
        ],
        [
            'number' => '950007', 'days' => 0,
            'lines' => ['OAC' => 200.00], 'deposit' => null,
            'nota' => 'para probar sobredepósito extremo (más que todos los pendientes)',
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

        // El DepositService registra actividad con auth()->user(); sin sesión
        // los logs quedarían sin causer.
        Auth::login($user);

        $service = app(DepositService::class);

        foreach (self::CASES as $case) {
            $manifest = $this->manifest($case, $supplier->id, $warehouses);

            if (! $manifest) {
                continue;
            }

            if ($case['legacy'] ?? false) {
                // Caso heredado: NO puede sembrarse por el servicio. Ver
                // seedLegacyOverDeposit() para el porqué.
                $this->seedLegacyOverDeposit($manifest, $case, $user->id);
            } elseif ($case['deposit'] !== null && $manifest->deposits()->count() === 0) {
                $service->createDeposit(
                    $manifest,
                    $this->depositData($case, $manifest),
                    $user->id,
                );

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
     * Crea el manifiesto con una factura por bodega, o devuelve el existente
     * sin tocarlo.
     *
     * @param  array{number: string, days: int, lines: array<string, float>}  $case
     * @param  \Illuminate\Support\Collection<string, Warehouse>  $warehouses
     */
    private function manifest(array $case, int $supplierId, $warehouses): ?Manifest
    {
        $existing = Manifest::where('number', $case['number'])->first();

        if ($existing) {
            $this->command?->line("  #{$case['number']} ya existe — se deja como está.");

            return $existing;
        }

        $date = now()->subDays($case['days'])->toDateString();

        $manifest = Manifest::create([
            'supplier_id' => $supplierId,
            // warehouse_id NULL a propósito: replica cómo entran los manifiestos
            // por la API de Jaremar (la bodega vive en las facturas). Es lo que
            // hace que el caso multi-bodega sea realista.
            'warehouse_id' => null,
            'number' => $case['number'],
            'date' => $date,
            'status' => 'imported',
        ]);

        $i = 0;
        foreach ($case['lines'] as $code => $total) {
            $warehouse = $warehouses[$code];
            $i++;

            Invoice::create([
                'manifest_id' => $manifest->id,
                'warehouse_id' => $warehouse->id,
                'status' => 'imported',
                'invoice_number' => 'PRB'.$case['number'].'-'.$i,
                'jaremar_id' => (int) $case['number'] * 10 + $i,
                'invoice_date' => $date,
                'due_date' => $date,
                'seller_id' => 'VEN01',
                'seller_name' => 'Vendedor de Pruebas',
                'client_id' => 'CLI'.$case['number'].$i,
                'client_name' => "Cliente de Pruebas {$case['number']}-{$code}",
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
                'total' => $total,
                'isv15' => 0,
                'isv18' => 0,
            ]);
        }

        $manifest->recalculateTotals();

        return $manifest->refresh();
    }

    /**
     * @param  array{number: string, days: int, deposit: float|null}  $case
     * @return array<string, mixed>
     */
    private function depositData(array $case, Manifest $manifest): array
    {
        $data = [
            'amount' => $case['deposit'],
            // Un día después del manifiesto (o hoy si el manifiesto es de hoy):
            // así la fecha del depósito nunca queda antes de la del manifiesto.
            'deposit_date' => now()->subDays(max(0, $case['days'] - 1))->toDateString(),
            'bank' => 'BAC',
            'reference' => 'PRB-'.$case['number'],
            'observations' => 'Depósito sembrado por DepositCasesSeeder.',
        ];

        // Si el monto supera el pendiente, el Service exige justificación.
        // Es justamente el caso del 950002 (deposita 500.01 sobre 500.00).
        if ($case['deposit'] > (float) $manifest->difference) {
            $data['justification'] = 'Caso de prueba: el banco redondeó hacia arriba en la transferencia.';
        }

        return $data;
    }

    /**
     * Siembra un manifiesto SOBRE-DEPOSITADO escribiendo el depósito y su
     * reparto directo en la base, sin pasar por DepositService.
     *
     * ─────────────────────────────────────────────────────────────────
     *  POR QUÉ NO SE PUEDE USAR EL SERVICIO
     * ─────────────────────────────────────────────────────────────────
     *  Si le pedimos al servicio que deposite 500.01 sobre un manifiesto que
     *  debe 500.00, hace exactamente lo que fue diseñado para hacer: reparte
     *  el centavo sobrante al manifiesto más antiguo con saldo pendiente. El
     *  manifiesto de origen queda en CERO, no en −0.01.
     *
     *  Es decir: con el código nuevo un manifiesto sobre-depositado por
     *  centavos ya no puede existir. Los que hay en producción (4 al
     *  28/07/2026) los generó el código VIEJO, cuando assertAmountWithinPending
     *  tenía un margen de tolerancia de +0.01 que dejaba pasar el exceso sin
     *  repartirlo ni justificarlo.
     *
     *  Para poder probar la acción "Ajustar Diferencia" hay que reproducir ese
     *  estado heredado tal cual: depósito y allocation escritos a mano, con el
     *  monto completo aplicado al propio manifiesto.
     *
     *  Si el manifiesto ya quedó en otro estado por una corrida anterior del
     *  seeder, se limpian sus depósitos y se reescribe — esto es una base de
     *  pruebas, no hay auditoría que preservar.
     *
     * @param  array{number: string, days: int, deposit: float|null}  $case
     */
    private function seedLegacyOverDeposit(Manifest $manifest, array $case, int $userId): void
    {
        $esperada = round((float) $manifest->total_to_deposit - (float) $case['deposit'], 2);

        if ($manifest->deposits()->exists() && round((float) $manifest->difference, 2) === $esperada) {
            $this->command?->line("  #{$case['number']} ya está sobre-depositado — se deja como está.");

            return;
        }

        // Limpiar SOLO los depósitos registrados desde este manifiesto. Las
        // allocations que otras boletas hayan dirigido acá no se tocan.
        //
        // OJO con los manifiestos AJENOS: si una corrida anterior repartió
        // excedente de esta boleta a otros manifiestos, al borrar esas
        // allocations sus totales quedan inflados — nadie los recalcula solo.
        // Se anotan antes de borrar y se recalculan después. Sin esto queda un
        // manifiesto con `total_deposited` fantasma, que es exactamente el tipo
        // de número que arruina una demo.
        $afectados = [];

        foreach ($manifest->deposits()->withTrashed()->get() as $previo) {
            $afectados = array_merge($afectados, $previo->allocations()->pluck('manifest_id')->all());
            $previo->allocations()->delete();
            $previo->forceDelete();
        }

        $ajenos = array_diff(array_unique($afectados), [$manifest->id]);

        foreach (Manifest::whereIn('id', $ajenos)->get() as $ajeno) {
            $ajeno->recalculateTotals();
            $this->command?->line("  ↳ recalculado #{$ajeno->number} (había recibido excedente de #{$case['number']})");
        }

        $deposito = Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => $case['deposit'],
            'allocated_amount' => $case['deposit'],
            'deposit_date' => now()->subDays(max(0, $case['days'] - 1))->toDateString(),
            'bank' => 'BAC',
            'reference' => 'PRB-'.$case['number'],
            'observations' => 'Depósito HEREDADO simulado (código anterior al reparto multi-manifiesto).',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        DepositAllocation::create([
            'deposit_id' => $deposito->id,
            'manifest_id' => $manifest->id,
            'amount' => $case['deposit'],
            'created_by' => $userId,
        ]);

        $manifest->recalculateTotals();
        $manifest->refresh();

        $this->command?->line(
            "  #{$case['number']} sembrado como sobre-depositado (diferencia ".
            number_format((float) $manifest->difference, 2).')'
        );
    }

    private function summary(): void
    {
        $rows = Manifest::whereIn('number', array_column(self::CASES, 'number'))
            ->orderBy('date')
            ->get(['number', 'date', 'total_to_deposit', 'total_deposited', 'difference', 'status']);

        $this->command?->newLine();
        $this->command?->info('Casos de prueba listos:');

        $this->command?->table(
            ['#', 'Fecha', 'A depositar', 'Depositado', 'Diferencia', 'Estado'],
            $rows->map(fn ($m) => [
                $m->number,
                $m->date?->format('d/m/Y'),
                number_format((float) $m->total_to_deposit, 2),
                number_format((float) $m->total_deposited, 2),
                number_format((float) $m->difference, 2),
                $m->status,
            ])->all()
        );

        $this->command?->info('Centavos pendientes en OAC: 0.50 + 0.25 + 0.32 = 1.07');
        $this->command?->info('→ Un depósito de HNL 801.07 desde el #950003 debe dejar 4 manifiestos en cero.');
    }
}
