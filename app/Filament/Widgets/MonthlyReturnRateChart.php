<?php

namespace App\Filament\Widgets;

use App\Models\Manifest;
use App\Support\WarehouseScope;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tendencia de la Tasa de Devolución — últimos 6 meses.
 *
 * Muestra mes a mes el porcentaje de devolución sobre la venta bruta:
 *   Tasa = (total_returns / total_invoices) × 100
 *
 * Una tasa al alza es señal de alerta temprana: puede indicar problemas
 * de calidad del producto, errores en despacho o clientes insatisfechos.
 * Un contador analiza esta tendencia antes de revisar valores absolutos.
 *
 * Referencia:
 *   < 5%   → Excelente
 *   5-10%  → Aceptable
 *   > 10%  → Requiere acción correctiva
 */
class MonthlyReturnRateChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 4;

    public function getHeading(): string
    {
        return 'Tasa de Devolución Mensual (%)';
    }

    public function getMaxHeight(): ?string
    {
        return '240px';
    }

    protected function getData(): array
    {
        // Cache key por-bodega: cada warehouse user ve su propia tasa de
        // devolución histórica; los globales ven el agregado.
        $cacheKey = WarehouseScope::cacheKey('dashboard:chart:return-rate');

        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () {
            $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());

            // Manifest no tiene warehouse_id directo — filtra vía invoices.
            $raw = WarehouseScope::applyViaRelation(Manifest::query(), 'invoices')
                ->whereDate('date', '>=', $months->first()->toDateString())
                ->select(
                    DB::raw("TO_CHAR(date, 'YYYY-MM') as month_key"),
                    DB::raw('COALESCE(SUM(total_invoices), 0) as facturado'),
                    DB::raw('COALESCE(SUM(total_returns),  0) as devoluciones')
                )
                ->groupBy('month_key')
                ->orderBy('month_key')
                ->get()
                ->keyBy('month_key');

            $labels = [];
            $rates = [];
            $devAbs = [];

            foreach ($months as $m) {
                $key = $m->format('Y-m');
                $row = $raw[$key] ?? null;
                $fact = $row ? (float) $row->facturado : 0;
                $devol = $row ? (float) $row->devoluciones : 0;
                $rate = $fact > 0 ? round(($devol / $fact) * 100, 2) : 0;

                $labels[] = $m->locale('es')->translatedFormat('M Y');
                $rates[] = $rate;
                $devAbs[] = round($devol, 2);
            }

            return compact('labels', 'rates', 'devAbs');
        });

        return [
            'datasets' => [
                [
                    'label' => 'Tasa de Devolución (%)',
                    'data' => $data['rates'],
                    'fill' => true,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.12)',
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'pointBackgroundColor' => 'rgba(239, 68, 68, 1)',
                    'pointRadius' => 5,
                    'tension' => 0.4,
                    'borderWidth' => 2,
                    'yAxisID' => 'y',
                ],
            ],
            'labels' => $data['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
                'annotation' => [
                    'annotations' => [
                        // Línea de referencia al 5% (umbral aceptable)
                        'line5' => [
                            'type' => 'line',
                            'yMin' => 5,
                            'yMax' => 5,
                            'borderColor' => 'rgba(234, 179, 8, 0.6)',
                            'borderWidth' => 1,
                            'borderDash' => [4, 4],
                            'label' => [
                                'content' => '5% límite aceptable',
                                'display' => true,
                                'position' => 'end',
                                'font' => ['size' => 10],
                            ],
                        ],
                        // Línea de referencia al 10% (umbral crítico)
                        'line10' => [
                            'type' => 'line',
                            'yMin' => 10,
                            'yMax' => 10,
                            'borderColor' => 'rgba(239, 68, 68, 0.5)',
                            'borderWidth' => 1,
                            'borderDash' => [4, 4],
                            'label' => [
                                'content' => '10% crítico',
                                'display' => true,
                                'position' => 'end',
                                'font' => ['size' => 10],
                            ],
                        ],
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'max' => 20,
                    'grid' => ['color' => 'rgba(156,163,175,0.15)'],
                    'ticks' => [
                        'callback' => 'function(v){ return v+"%"; }',
                    ],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
