<?php

namespace App\Filament\Widgets;

use App\Models\Manifest;
use App\Support\WarehouseScope;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

/**
 * Distribución de manifiestos por estado.
 * Donut chart — permite ver de un vistazo cuántos manifiestos están
 * en cada etapa del ciclo de vida.
 */
class ManifestStatusChart extends ChartWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 5;

    public function getHeading(): string
    {
        return 'Distribución por Estado';
    }

    public function getMaxHeight(): ?string
    {
        return '260px';
    }

    protected function getData(): array
    {
        // Cache key por-bodega: globales comparten una entrada; cada warehouse
        // user tiene la suya. Sin esto un encargado vería la caché del admin.
        $cacheKey = WarehouseScope::cacheKey('dashboard:chart:manifest-status');

        $counts = Cache::remember($cacheKey, now()->addMinutes(5), function () {
            // Manifest no tiene warehouse_id directo — filtra vía invoices.
            $query = WarehouseScope::applyViaRelation(
                Manifest::query(),
                'invoices'
            );

            return $query->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
        });

        $labels = [
            'pending' => 'Pendiente',
            'processing' => 'En Proceso',
            'imported' => 'Importado',
            'closed' => 'Cerrado',
        ];

        $colors = [
            'pending' => 'rgba(234, 179, 8, 0.85)',
            'processing' => 'rgba(59, 130, 246, 0.85)',
            'imported' => 'rgba(168, 85, 247, 0.85)',
            'closed' => 'rgba(107, 114, 128, 0.85)',
        ];

        $borderColors = [
            'pending' => 'rgba(234, 179, 8, 1)',
            'processing' => 'rgba(59, 130, 246, 1)',
            'imported' => 'rgba(168, 85, 247, 1)',
            'closed' => 'rgba(107, 114, 128, 1)',
        ];

        $data = [];
        $bgColors = [];
        $borders = [];
        $lbls = [];

        foreach ($labels as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $lbls[] = $label.' ('.$counts[$key].')';
                $data[] = $counts[$key];
                $bgColors[] = $colors[$key];
                $borders[] = $borderColors[$key];
            }
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $bgColors,
                    'borderColor' => $borders,
                    'borderWidth' => 2,
                    'hoverOffset' => 6,
                ],
            ],
            'labels' => $lbls,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => ['padding' => 16],
                ],
            ],
            'cutout' => '65%',
        ];
    }
}
