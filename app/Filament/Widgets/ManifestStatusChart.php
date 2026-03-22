<?php

namespace App\Filament\Widgets;

use App\Models\Manifest;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

/**
 * Distribución de manifiestos por estado.
 * Donut chart — permite ver de un vistazo cuántos manifiestos están
 * en cada etapa del ciclo de vida.
 */
class ManifestStatusChart extends ChartWidget
{
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
        $counts = Cache::remember('dashboard:chart:manifest-status', now()->addMinutes(5), function () {
            return Manifest::selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
        });

        $labels = [
            'pending'    => 'Pendiente',
            'processing' => 'En Proceso',
            'imported'   => 'Importado',
            'closed'     => 'Cerrado',
        ];

        $colors = [
            'pending'    => 'rgba(234, 179, 8, 0.85)',
            'processing' => 'rgba(59, 130, 246, 0.85)',
            'imported'   => 'rgba(168, 85, 247, 0.85)',
            'closed'     => 'rgba(107, 114, 128, 0.85)',
        ];

        $borderColors = [
            'pending'    => 'rgba(234, 179, 8, 1)',
            'processing' => 'rgba(59, 130, 246, 1)',
            'imported'   => 'rgba(168, 85, 247, 1)',
            'closed'     => 'rgba(107, 114, 128, 1)',
        ];

        $data        = [];
        $bgColors    = [];
        $borders     = [];
        $lbls        = [];

        foreach ($labels as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $lbls[]      = $label . ' (' . $counts[$key] . ')';
                $data[]      = $counts[$key];
                $bgColors[]  = $colors[$key];
                $borders[]   = $borderColors[$key];
            }
        }

        return [
            'datasets' => [
                [
                    'data'            => $data,
                    'backgroundColor' => $bgColors,
                    'borderColor'     => $borders,
                    'borderWidth'     => 2,
                    'hoverOffset'     => 6,
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
                    'labels'   => ['padding' => 16],
                ],
            ],
            'cutout' => '65%',
        ];
    }
}
