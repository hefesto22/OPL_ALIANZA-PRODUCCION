<?php

namespace App\Filament\Widgets;

use App\Models\ManifestWarehouseTotal;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Muestra la venta neta (facturado − devoluciones aprobadas) por bodega.
 *
 * Usa manifest_warehouse_totals que ya tiene los totales pre-calculados,
 * evitando queries pesadas sobre invoices/returns.
 * Una sola query GROUP BY reemplaza el bucle N+1 anterior.
 */
class ReturnsByWarehouseChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 6;

    public function getHeading(): string
    {
        return 'Ventas Netas por Bodega';
    }

    public function getMaxHeight(): ?string
    {
        return '280px';
    }

    protected function getData(): array
    {
        $rows = Cache::remember('dashboard:chart:warehouse-sales', now()->addMinutes(5), function () {
            return ManifestWarehouseTotal::query()
                ->join('warehouses', 'manifest_warehouse_totals.warehouse_id', '=', 'warehouses.id')
                ->join('manifests', 'manifest_warehouse_totals.manifest_id', '=', 'manifests.id')
                ->whereNull('manifests.deleted_at')
                ->select(
                    'warehouses.code as code',
                    'warehouses.name as name',
                    DB::raw('SUM(manifest_warehouse_totals.total_invoices) as total_facturado'),
                    DB::raw('SUM(manifest_warehouse_totals.total_returns)  as total_devoluciones'),
                    DB::raw('SUM(manifest_warehouse_totals.total_invoices)
                            - SUM(manifest_warehouse_totals.total_returns) as total_neto'),
                )
                ->groupBy('warehouses.id', 'warehouses.code', 'warehouses.name')
                ->orderByDesc('total_neto')
                ->get();
        });

        $labels = $rows->pluck('code')->toArray();
        $facturado = $rows->pluck('total_facturado')->map(fn ($v) => round((float) $v, 2))->toArray();
        $devoluciones = $rows->pluck('total_devoluciones')->map(fn ($v) => round((float) $v, 2))->toArray();
        $neto = $rows->pluck('total_neto')->map(fn ($v) => round((float) $v, 2))->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Total Facturado',
                    'data' => $facturado,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.6)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Venta Neta',
                    'data' => $neto,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.7)',
                    'borderColor' => 'rgba(34, 197, 94, 1)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Devoluciones',
                    'data' => $devoluciones,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.6)',
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
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
                    'callbacks' => [
                        // El formateo se hace en JS del lado del cliente vía Filament
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => 'function(v){ return "L. "+v.toLocaleString(); }',
                    ],
                ],
            ],
        ];
    }
}
