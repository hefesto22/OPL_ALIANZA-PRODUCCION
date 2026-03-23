<?php

namespace App\Filament\Widgets;

use App\Models\Deposit;
use App\Models\Manifest;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Illuminate\Support\Facades\DB;

/**
 * Comparativa mensual Ventas vs Cobros — últimos 6 meses.
 *
 * Muestra en paralelo:
 *   - Venta Neta (total_to_deposit de manifiestos)  — barra azul
 *   - Total Cobrado (depósitos recibidos)            — barra verde
 *
 * La brecha entre ambas barras revela el GAP de liquidez:
 * un contador experimentado identifica de inmediato si los cobros
 * van por detrás de las ventas y cuántos meses lleva esa tendencia.
 */
class MonthlySalesVsCollectionsChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'Ventas vs Cobros — Últimos 6 Meses';
    }

    public function getMaxHeight(): ?string
    {
        return '260px';
    }

    protected function getData(): array
    {
        $data = Cache::remember('dashboard:chart:sales-vs-collections', now()->addMinutes(5), function () {
            // Generar los 6 meses incluyendo el actual, del más antiguo al más reciente.
            $months = collect(range(5, 0))->map(fn($i) => now()->subMonths($i)->startOfMonth());

            // ── Ventas netas por mes (fecha del manifiesto) ──────────────
            $rawSales = Manifest::query()
                ->whereDate('date', '>=', $months->first()->toDateString())
                ->select(
                    DB::raw("TO_CHAR(date, 'YYYY-MM') as month_key"),
                    DB::raw('SUM(total_to_deposit) as total')
                )
                ->groupBy('month_key')
                ->orderBy('month_key')
                ->pluck('total', 'month_key');

            // ── Cobros (depósitos) por mes ────────────────────────────────
            $rawDeposits = Deposit::query()
                ->whereDate('deposit_date', '>=', $months->first()->toDateString())
                ->select(
                    DB::raw("TO_CHAR(deposit_date, 'YYYY-MM') as month_key"),
                    DB::raw('SUM(amount) as total')
                )
                ->groupBy('month_key')
                ->orderBy('month_key')
                ->pluck('total', 'month_key');

            $labels   = [];
            $sales    = [];
            $deposits = [];

            foreach ($months as $m) {
                $key        = $m->format('Y-m');
                $labels[]   = $m->locale('es')->translatedFormat('M Y');
                $sales[]    = round((float) ($rawSales[$key]    ?? 0), 2);
                $deposits[] = round((float) ($rawDeposits[$key] ?? 0), 2);
            }

            return compact('labels', 'sales', 'deposits');
        });

        return [
            'datasets' => [
                [
                    'label'           => 'Venta Neta',
                    'data'            => $data['sales'],
                    'backgroundColor' => 'rgba(59, 130, 246, 0.65)',
                    'borderColor'     => 'rgba(59, 130, 246, 1)',
                    'borderWidth'     => 1,
                    'borderRadius'    => 4,
                ],
                [
                    'label'           => 'Total Cobrado',
                    'data'            => $data['deposits'],
                    'backgroundColor' => 'rgba(34, 197, 94, 0.65)',
                    'borderColor'     => 'rgba(34, 197, 94, 1)',
                    'borderWidth'     => 1,
                    'borderRadius'    => 4,
                ],
            ],
            'labels' => $data['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'tooltip' => [
                    'mode'      => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid'        => ['color' => 'rgba(156,163,175,0.15)'],
                    'ticks'       => [
                        'callback' => 'function(v){ return "L. "+v.toLocaleString(); }',
                    ],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
