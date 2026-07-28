<?php

declare(strict_types=1);

namespace Database\Seeders;

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
 * Es idempotente: si los manifiestos ya existen, no hace nada. Para regenerar,
 * borrarlos primero desde el panel.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  QUÉ SE PUEDE PROBAR CON CADA UNO
 * ─────────────────────────────────────────────────────────────────────
 *  950001  OAC, hace 20 días  · L 1,000.00 · depositado 999.68
 *          → le faltan L 0.32. Es el manifiesto viejo varado por centavos.
 *            Debe aparecer en el reparto del 950003 y quedar en cero solo.
 *
 *  950002  OAC, hace 15 días  · L 500.00   · depositado 500.01
 *          → le SOBRA L 0.01. El reparto NO puede arreglarlo (no hay plata
 *            que mover): solo se cierra con "Ajustar Diferencia".
 *
 *  950003  OAC, hoy           · L 800.00   · sin depósitos
 *          → el manifiesto de trabajo. Registrar acá UNA boleta de L 800.32
 *            debe cubrirlo y tapar los 0.32 del 950001 automáticamente.
 *
 *  950004  OAS, hace 10 días  · L 300.00   · sin depósitos
 *          → control de aislamiento: es de OTRA bodega y NO debe recibir
 *            nada del excedente depositado en el 950003 (que es de OAC).
 */
class DepositCasesSeeder extends Seeder
{
    /**
     * Bases de datos donde este seeder NUNCA debe correr.
     *
     * Se identifica producción por el nombre de la base y no por APP_ENV
     * porque pruebas.hozana.cloud también corre con APP_ENV=production
     * (ver config del entorno). Si algún día se agrega otra instancia
     * productiva, su base va acá.
     *
     * @var array<int, string>
     */
    private const PRODUCTION_DATABASES = ['distribuidora_hozana'];

    public function run(): void
    {
        // El guard NO puede ser app()->environment('production'): el entorno de
        // pruebas (pruebas.hozana.cloud) corre con APP_ENV=production a
        // propósito, y ahí SÍ queremos sembrar. La señal confiable de que
        // estamos en el servidor real es el nombre de la base.
        $database = DB::connection()->getDatabaseName();

        if (in_array($database, self::PRODUCTION_DATABASES, true)) {
            $this->command?->error(
                "DepositCasesSeeder ABORTADO: la conexión apunta a la base de producción ({$database})."
            );

            return;
        }

        $this->command?->info("Sembrando casos de prueba en la base: {$database}");

        $supplier = Supplier::query()->first();
        $warehouses = Warehouse::whereIn('code', ['OAC', 'OAS'])->get()->keyBy('code');
        $user = User::query()->orderBy('id')->first();

        if (! $supplier || $warehouses->count() < 2 || ! $user) {
            $this->command?->error('Faltan proveedor, bodegas OAC/OAS o usuarios. Corré los seeders base primero.');

            return;
        }

        // El DepositService registra actividad con auth()->user(); sin sesión
        // los logs quedarían sin causer y el seeder fallaría al leerlo.
        Auth::login($user);

        $service = app(DepositService::class);

        // ── 950001: le faltan L 0.32 ────────────────────────────────
        $viejo = $this->manifest('950001', $supplier->id, $warehouses['OAC'], 1000.00, 20);
        if ($viejo && $viejo->deposits()->count() === 0) {
            $service->createDeposit($viejo, $this->depositData(999.68, 19), $user->id);
        }

        // ── 950002: le SOBRA L 0.01 ─────────────────────────────────
        // Se registra con justificación porque el monto supera el pendiente
        // — exactamente el camino que antes hacía el margen oculto de 0.01.
        $sobrante = $this->manifest('950002', $supplier->id, $warehouses['OAC'], 500.00, 15);
        if ($sobrante && $sobrante->deposits()->count() === 0) {
            $service->createDeposit(
                $sobrante,
                $this->depositData(500.01, 14) + [
                    'justification' => 'Caso de prueba: el banco redondeó un centavo hacia arriba en la transferencia.',
                ],
                $user->id,
            );
        }

        // ── 950003: manifiesto de trabajo, sin depósitos ────────────
        $this->manifest('950003', $supplier->id, $warehouses['OAC'], 800.00, 0);

        // ── 950004: control de otra bodega ──────────────────────────
        $this->manifest('950004', $supplier->id, $warehouses['OAS'], 300.00, 10);

        Auth::logout();

        $this->command?->info('Casos de prueba listos: 950001 (falta 0.32), 950002 (sobra 0.01), 950003 (a depositar), 950004 (otra bodega).');
    }

    /**
     * Crea el manifiesto con UNA factura del total indicado, o devuelve el
     * existente sin tocarlo (idempotencia).
     */
    private function manifest(string $number, int $supplierId, Warehouse $warehouse, float $total, int $daysAgo): ?Manifest
    {
        $existing = Manifest::where('number', $number)->first();

        if ($existing) {
            $this->command?->line("Manifiesto #{$number} ya existe — se deja como está.");

            return $existing;
        }

        $date = now()->subDays($daysAgo)->toDateString();

        $manifest = Manifest::create([
            'supplier_id' => $supplierId,
            // warehouse_id NULL a propósito: replica cómo entran los
            // manifiestos por la API de Jaremar (la bodega vive en las
            // facturas). Así el reparto se prueba contra el caso real.
            'warehouse_id' => null,
            'number' => $number,
            'date' => $date,
            'status' => 'imported',
        ]);

        Invoice::create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'imported',
            'invoice_number' => 'PRB'.$number,
            'jaremar_id' => (int) $number,
            'invoice_date' => $date,
            'due_date' => $date,
            'seller_id' => 'VEN01',
            'seller_name' => 'Vendedor de Pruebas',
            'client_id' => 'CLI'.$number,
            'client_name' => 'Cliente de Pruebas '.$number,
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

        $manifest->recalculateTotals();

        return $manifest->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function depositData(float $amount, int $daysAgo): array
    {
        return [
            'amount' => $amount,
            'deposit_date' => now()->subDays($daysAgo)->toDateString(),
            'bank' => 'BAC',
            'reference' => 'PRB-'.now()->timestamp.'-'.random_int(100, 999),
            'observations' => 'Depósito sembrado por DepositCasesSeeder.',
        ];
    }
}
