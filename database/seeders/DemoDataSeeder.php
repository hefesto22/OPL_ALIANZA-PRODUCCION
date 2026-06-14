<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReturnService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de DEMO — datos realistas para que Jaremar vea el flujo completo.
 *
 * Crea 5 manifiestos (900001–900005) repartidos entre OAC/OAS/OAO, fechados a
 * lo largo de ~2 semanas, cada uno con 5 facturas que mezclan productos CJ
 * (caja) y UN (unidad). Sobre varias facturas registra devoluciones reales de
 * tres tipos:
 *   - caja completa            (CJ: quantity_box>0, quantity=0)
 *   - unidades sueltas         (UN: quantity_box=0, quantity>0)
 *   - mixta caja + sueltas     (CJ: quantity_box>0, quantity>0)
 *
 * Las devoluciones NO se insertan a mano: se crean con ReturnService::createReturn(),
 * el mismo servicio del flujo real, así que quedan auto-aprobadas, con total
 * calculado en servidor, returned_quantity de las líneas, estado de factura y
 * totales del manifiesto recalculados, y auditoría — datos consistentes, no falsos.
 *
 * Es IDEMPOTENTE: borra cualquier dato de demo previo (manifiestos 900001–900005
 * y todo lo que cuelga de ellos) antes de recrear.
 *
 * Uso (NO va en DatabaseSeeder; se corre a demanda):
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    /** Rango de números reservado para datos de demo. */
    private const DEMO_NUMBERS = ['900001', '900002', '900003', '900004', '900005'];

    /**
     * Catálogo de productos (precio unitario = price_min_sale, sin impuesto).
     * 'cj' indica venta por caja; 'factor' = unidades por caja; 'iva' si grava 15%.
     */
    private const CATALOG = [
        // ── Productos CJ (caja) ───────────────────────────────────────────
        ['id' => '50470402', 'desc' => 'CENTELLABARRA ROSADO 400GX3X4', 'cj' => true,  'factor' => 12, 'unit' => 15.00, 'iva' => true],
        ['id' => '50470403', 'desc' => 'CENTELLABARRA AMARILL 400GX3X4', 'cj' => true,  'factor' => 12, 'unit' => 15.00, 'iva' => true],
        ['id' => '52480087', 'desc' => '3PACK LIMPIOX LIMON 245g X3X8',  'cj' => true,  'factor' => 24, 'unit' => 9.03,  'iva' => true],
        ['id' => '52480088', 'desc' => 'LIMPIOX MEGA DISCO LIMON 425g',  'cj' => true,  'factor' => 20, 'unit' => 14.12, 'iva' => true],
        // ── Productos UN (unidad) ─────────────────────────────────────────
        ['id' => '30110205', 'desc' => 'ORISOL LIGHT OLIVA 410mL 1/24',  'cj' => false, 'factor' => 24, 'unit' => 31.20, 'iva' => true],
        ['id' => '81800012', 'desc' => 'KETCHUP 8X12X87GR',              'cj' => false, 'factor' => 96, 'unit' => 6.69,  'iva' => true],
        ['id' => '52480038', 'desc' => 'LIMPIOX DISKETT LIMON 115GRX72', 'cj' => false, 'factor' => 72, 'unit' => 4.17,  'iva' => true],
        ['id' => '86800002', 'desc' => 'FRIJOLES 24X200GR',              'cj' => false, 'factor' => 24, 'unit' => 14.36, 'iva' => true],
        ['id' => '86800016', 'desc' => 'FRIJOLES 24X360G',               'cj' => false, 'factor' => 24, 'unit' => 22.09, 'iva' => true],
        ['id' => '01020021', 'desc' => 'MANTECA DOMESTICA DORAL 50X409', 'cj' => false, 'factor' => 50, 'unit' => 20.00, 'iva' => false],
        ['id' => '80800013', 'desc' => 'PASTA NORMAL 8X12X87GR',         'cj' => false, 'factor' => 96, 'unit' => 7.93,  'iva' => true],
        ['id' => '50491315', 'desc' => 'MAX SBA DUOX AZUL BCS 400GX4X5', 'cj' => false, 'factor' => 20, 'unit' => 20.54, 'iva' => true],
    ];

    /** Clientes (pulperías) hondureños con RTN de formato válido. */
    private const CLIENTS = [
        ['id' => '98065401', 'name' => 'PULPERIA ASLYN',          'rtn' => '12181986001440', 'depto' => 'LA PAZ',        'mun' => 'Santiago de Puringla'],
        ['id' => '98065403', 'name' => 'PULPERIA ESMERALDA',      'rtn' => '12171992001614', 'depto' => 'LA PAZ',        'mun' => 'Santa Maria'],
        ['id' => '98065584', 'name' => 'PUESTO 21',               'rtn' => '12081983002970', 'depto' => 'LA PAZ',        'mun' => 'Marcala'],
        ['id' => '98066120', 'name' => 'PULPERIA LA BENDICION',   'rtn' => '04011990014521', 'depto' => 'COPAN',         'mun' => 'Santa Rosa de Copan'],
        ['id' => '98066345', 'name' => 'ABARROTERIA EL AHORRO',   'rtn' => '14021985007733', 'depto' => 'OCOTEPEQUE',    'mun' => 'Ocotepeque'],
        ['id' => '98066890', 'name' => 'VENTA DONA MARTA',        'rtn' => '15071979002214', 'depto' => 'SANTA BARBARA', 'mun' => 'Santa Barbara'],
        ['id' => '98067012', 'name' => 'PULPERIA SAN JOSE',       'rtn' => '04081988009910', 'depto' => 'COPAN',         'mun' => 'La Entrada'],
        ['id' => '98067230', 'name' => 'MINISUPER LA ECONOMIA',   'rtn' => '15031991004458', 'depto' => 'SANTA BARBARA', 'mun' => 'Quimistan'],
        ['id' => '98067455', 'name' => 'PULPERIA EL ENCUENTRO',   'rtn' => '14051987001172', 'depto' => 'OCOTEPEQUE',    'mun' => 'San Marcos'],
        ['id' => '98067788', 'name' => 'DISTRIBUIDORA CENTRAL',   'rtn' => '12101983006690', 'depto' => 'LA PAZ',        'mun' => 'La Paz'],
    ];

    /** Vendedores. */
    private const SELLERS = [
        ['id' => '11983', 'name' => 'WALTER REYNALDO MARADIAGA'],
        ['id' => '12044', 'name' => 'JOSE LUIS HERNANDEZ'],
        ['id' => '12190', 'name' => 'CARLOS ROBERTO FLORES'],
    ];

    public function run(): void
    {
        $user = User::query()->orderBy('id')->first();
        if (! $user) {
            $this->command->error('No hay usuarios en la BD. Corre primero los seeders base (RolePermissionSeeder + AdminUserSeeder).');

            return;
        }

        $supplier = Supplier::where('is_active', true)->first()
            ?? Supplier::first();
        if (! $supplier) {
            $this->command->error('No hay proveedor. Corre SupplierSeeder primero.');

            return;
        }

        $warehouses = Warehouse::whereIn('code', ['OAC', 'OAS', 'OAO'])->get()->keyBy('code');
        if ($warehouses->count() < 3) {
            $this->command->error('Faltan bodegas OAC/OAS/OAO. Corre WarehouseSeeder primero.');

            return;
        }

        $reasons = ReturnReason::whereIn('code', ['BE-01', 'BE-03', 'PNC-02', 'PNC-05'])
            ->pluck('id', 'code');
        if ($reasons->isEmpty()) {
            $this->command->error('Faltan motivos de devolución. Corre ReturnReasonSeeder primero.');

            return;
        }

        $this->cleanPreviousDemo();

        $service = app(ReturnService::class);

        // ── Plan de los 5 manifiestos: bodega + fecha (repartidos en ~2 semanas) ──
        $today = Carbon::today();
        $plan = [
            ['number' => '900001', 'wh' => 'OAC', 'date' => $today->copy()->subDays(11)],
            ['number' => '900002', 'wh' => 'OAS', 'date' => $today->copy()->subDays(9)],
            ['number' => '900003', 'wh' => 'OAO', 'date' => $today->copy()->subDays(7)],
            ['number' => '900004', 'wh' => 'OAC', 'date' => $today->copy()->subDays(4)],
            ['number' => '900005', 'wh' => 'OAS', 'date' => $today->copy()->subDays(2)],
        ];

        $invoiceCounter = 90_000_001; // base para invoice_number e Id internos
        $totalReturns = 0;

        foreach ($plan as $mIndex => $m) {
            $warehouse = $warehouses[$m['wh']];

            $manifest = Manifest::create([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'number' => $m['number'],
                'date' => $m['date']->toDateString(),
                'status' => 'imported',
                'created_by' => $user->id,
            ]);

            // 5 facturas por manifiesto, cada una con 3 líneas (mezcla CJ/UN).
            $invoices = [];
            for ($i = 0; $i < 5; $i++) {
                $invoices[] = $this->makeInvoice($manifest, $warehouse, $invoiceCounter++, $i + ($mIndex * 5));
            }

            // Recalcular totales del manifiesto tras crear las facturas.
            $manifest->recalculateTotals();

            // ── Devoluciones: sobre 3 de las 5 facturas, con tipos y fechas variadas ──
            // Tipo rotado por manifiesto para cubrir los 3 casos en el set.
            $returnPlan = [
                ['inv' => 0, 'kind' => 'caja',    'offset' => 1, 'reason' => 'BE-03'],
                ['inv' => 2, 'kind' => 'sueltas', 'offset' => 2, 'reason' => 'PNC-02'],
                ['inv' => 3, 'kind' => 'mixta',   'offset' => 3, 'reason' => 'BE-01'],
            ];

            // Devolución COMPLETA (factura entera → tipo 'total') en manifiestos
            // alternos, para que la demo muestre tanto 'Parcial' como 'Total'.
            if ($mIndex % 2 === 0) {
                $returnPlan[] = ['inv' => 4, 'kind' => 'total', 'offset' => 4, 'reason' => 'PNC-05'];
            }

            foreach ($returnPlan as $rp) {
                $returnDate = $m['date']->copy()->addDays($rp['offset']);
                if ($returnDate->gt($today)) {
                    $returnDate = $today->copy(); // nunca futura
                }

                $created = $this->makeReturn(
                    $service,
                    $invoices[$rp['inv']],
                    $rp['kind'],
                    $returnDate,
                    (int) $reasons[$rp['reason']],
                    $user->id,
                );

                if ($created) {
                    $totalReturns++;
                }
            }

            $this->command->info("✔ Manifiesto #{$m['number']} ({$m['wh']}, {$m['date']->toDateString()}) — 5 facturas + devoluciones.");
        }

        $this->command->info("✅ Demo lista: 5 manifiestos, 25 facturas y {$totalReturns} devoluciones (cajas, sueltas y mixtas) en ~2 semanas.");
    }

    /**
     * Crea una factura con 3 líneas mezclando productos CJ y UN.
     * Garantiza al menos una línea CJ (≥2 cajas) y una UN (≥12 uds) para que
     * las devoluciones de demo tengan disponible suficiente.
     */
    private function makeInvoice(Manifest $manifest, Warehouse $warehouse, int $counter, int $seq): Invoice
    {
        $client = self::CLIENTS[$seq % count(self::CLIENTS)];
        $seller = self::SELLERS[$seq % count(self::SELLERS)];

        // Selección de 3 productos: 1 CJ fijo, 1 UN fijo, 1 rotativo — variedad estable.
        $cj = self::CATALOG[$seq % 4];               // índices 0..3 = CJ
        $un = self::CATALOG[4 + ($seq % 8)];         // índices 4..11 = UN
        $extra = self::CATALOG[($seq + 3) % count(self::CATALOG)];

        $lineSpecs = [
            ['prod' => $cj,    'qty' => 2],   // 2 cajas
            ['prod' => $un,    'qty' => 12],  // 12 unidades sueltas
            ['prod' => $extra, 'qty' => $extra['cj'] ? 1 : 6],
        ];

        $invoiceNumber = '002-001-01-0490'.str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);

        $invoice = Invoice::create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'imported',
            'invoice_number' => $invoiceNumber,
            'jaremar_id' => $counter,
            'invoice_date' => $manifest->date->toDateString(),
            'due_date' => $manifest->date->toDateString(),
            'seller_id' => $seller['id'],
            'seller_name' => $seller['name'],
            'client_id' => $client['id'],
            'client_name' => $client['name'],
            'client_rtn' => $client['rtn'],
            'deliver_to' => $client['name'],
            'department' => $client['depto'],
            'municipality' => $client['mun'],
            'address' => "Barrio Centro, {$client['mun']}, {$client['depto']}",
            'route_number' => '230',
            'payment_type' => 'CONTADO',
            'credit_days' => 0,
            'invoice_type' => 'FAC',
            'cai' => '2F0037-619ACD-2A66E0-63BE03-0909DC-56',
            'range_start' => '002-001-01-04000001',
            'range_end' => '002-001-01-04999999',
            'total' => 0,
            'isv15' => 0,
            'isv18' => 0,
        ]);

        $invoiceTotal = 0.0;
        $invoiceIsv = 0.0;
        $gravadoTotal = 0.0;

        foreach ($lineSpecs as $idx => $spec) {
            $p = $spec['prod'];
            $factor = (int) $p['factor'];
            $fractions = $p['cj'] ? $spec['qty'] * $factor : $spec['qty'];
            $boxes = $p['cj'] ? $spec['qty'] : 0;
            $unit = (float) $p['unit'];

            $subtotal = round($fractions * $unit, 2);
            $tax = $p['iva'] ? round($subtotal * 0.15, 2) : 0.0;
            $lineTotal = round($subtotal + $tax, 2);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'jaremar_line_id' => $counter * 10 + $idx,
                'invoice_jaremar_id' => $counter,
                'line_number' => $idx + 1,
                'product_id' => $p['id'],
                'product_description' => $p['desc'],
                'product_type' => 'A',
                'unit_sale' => $p['cj'] ? 'CJ' : 'UN',
                'quantity_fractions' => $fractions,
                'quantity_decimal' => round($fractions / $factor, 4),
                'quantity_box' => $boxes,
                'quantity_min_sale' => $fractions,
                'conversion_factor' => $factor,
                'cost' => 0,
                'price' => round($unit * $factor, 4),
                'price_min_sale' => $unit,
                'subtotal' => $subtotal,
                'discount' => 0,
                'discount_percent' => 0,
                'tax' => $tax,
                'tax_percent' => $p['iva'] ? 15.0 : 0.0,
                'tax18' => 0,
                'total' => $lineTotal,
                'returned_quantity' => 0,
                'weight' => 0,
                'volume' => 0,
            ]);

            $invoiceTotal += $lineTotal;
            $invoiceIsv += $tax;
            $gravadoTotal += $p['iva'] ? $lineTotal : 0;
        }

        $invoice->update([
            'total' => round($invoiceTotal, 2),
            'isv15' => round($invoiceIsv, 2),
            'importe_gravado' => round($gravadoTotal / 1.15, 2),
            'importe_gravado_isv15' => round($invoiceIsv, 2),
            'importe_gravado_total' => round($gravadoTotal, 2),
        ]);

        return $invoice->fresh('lines');
    }

    /**
     * Registra una devolución real vía ReturnService según el tipo:
     *   'caja'    → 1 caja completa de la primera línea CJ.
     *   'sueltas' → 6 unidades sueltas de la primera línea UN.
     *   'mixta'   → 1 caja + 4 unidades sueltas de la primera línea CJ.
     *
     * Luego alinea processed_date a la fecha de devolución para que el histórico
     * quede repartido en el tiempo (createReturn fija processed_date = hoy).
     */
    private function makeReturn(
        ReturnService $service,
        Invoice $invoice,
        string $kind,
        Carbon $returnDate,
        int $reasonId,
        int $userId,
    ): bool {
        $cjLine = $invoice->lines->firstWhere('unit_sale', 'CJ');
        $unLine = $invoice->lines->firstWhere('unit_sale', 'UN');

        $lines = match ($kind) {
            'caja' => $cjLine ? [$this->lineData($cjLine, 1, 0)] : [],
            'sueltas' => $unLine ? [$this->lineData($unLine, 0, 6)] : [],
            'mixta' => $cjLine ? [$this->lineData($cjLine, 1, 4)] : [],
            // Devolución completa: TODA la cantidad disponible de cada línea.
            'total' => $invoice->lines->map(fn ($line) => $this->lineData(
                $line,
                $line->unit_sale === 'CJ' ? (float) $line->quantity_box : 0.0,
                $line->unit_sale === 'CJ' ? 0.0 : (float) $line->quantity_fractions,
            ))->all(),
            default => [],
        };

        if (empty($lines)) {
            return false;
        }

        $return = $service->createReturn([
            'invoice_id' => $invoice->id,
            'return_reason_id' => $reasonId,
            'return_date' => $returnDate->toDateString(),
            'created_by' => $userId,
            'lines' => $lines,
        ]);

        // Repartir el histórico: processed_date = fecha de la devolución.
        $return->forceFill([
            'processed_date' => $returnDate->toDateString(),
            'processed_time' => '14:30:00',
            'reviewed_by' => $userId,
            'reviewed_at' => $returnDate->copy()->setTime(14, 35),
        ])->saveQuietly();

        return true;
    }

    /** Arma una línea del payload de devolución desde una InvoiceLine real. */
    private function lineData(InvoiceLine $line, float $boxes, float $units): array
    {
        return [
            'invoice_line_id' => $line->id,
            'line_number' => $line->line_number,
            'product_id' => $line->product_id,
            'product_description' => $line->product_description,
            'quantity_box' => $boxes,
            'quantity' => $units,
        ];
    }

    /** Borra (hard) todos los datos de demo previos por rango de número. */
    private function cleanPreviousDemo(): void
    {
        $manifestIds = DB::table('manifests')->whereIn('number', self::DEMO_NUMBERS)->pluck('id');
        if ($manifestIds->isEmpty()) {
            return;
        }

        $invoiceIds = DB::table('invoices')->whereIn('manifest_id', $manifestIds)->pluck('id');
        $returnIds = DB::table('returns')->whereIn('manifest_id', $manifestIds)->pluck('id');

        DB::table('return_lines')->whereIn('return_id', $returnIds)->delete();
        DB::table('returns')->whereIn('manifest_id', $manifestIds)->delete();
        DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('invoices')->whereIn('manifest_id', $manifestIds)->delete();
        DB::table('manifest_warehouse_totals')->whereIn('manifest_id', $manifestIds)->delete();
        DB::table('manifests')->whereIn('id', $manifestIds)->delete();

        $this->command->warn('Datos de demo previos (900001–900005) eliminados antes de recrear.');
    }
}
