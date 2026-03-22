<?php

namespace App\Filament\Widgets;

use App\Models\Manifest;
use App\Support\WarehouseScope;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Análisis de antigüedad de la cartera abierta (Aging Analysis).
 *
 * Clasifica los manifiestos activos por cuántos días llevan abiertos
 * sin estar completamente depositados. Es el indicador más crítico para
 * un contador: cuanto más vieja la cartera, mayor el riesgo de no cobro.
 *
 * Benchmarks:
 *   0 – 7 días  → Corriente      (verde)    — cobro en tiempo normal
 *   8 – 14 días → Seguimiento    (info)     — requiere atención
 *  15 – 30 días → Vencida        (naranja)  — riesgo moderado
 *  30+  días    → Crítica        (rojo)     — riesgo alto, acción inmediata
 */
class ManifestAgingWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public function getHeading(): ?string
    {
        return 'Antigüedad de Cartera Abierta (Aging)';
    }

    protected function getStats(): array
    {
        // Clave de caché única por bodega
        $cacheKey    = WarehouseScope::cacheKey('dashboard:aging');
        $warehouseId = WarehouseScope::getWarehouseId();

        $aging = Cache::remember($cacheKey, now()->addMinutes(3), function () use ($warehouseId) {
            $activeStatuses = ['pending', 'processing', 'imported'];

            $query = $warehouseId
                ? Manifest::where('warehouse_id', $warehouseId)
                : Manifest::query();

            $rows = $query
                ->whereIn('status', $activeStatuses)
                ->selectRaw("
                    CASE
                        WHEN CURRENT_DATE - date <=  7 THEN '0-7'
                        WHEN CURRENT_DATE - date <= 14 THEN '8-14'
                        WHEN CURRENT_DATE - date <= 30 THEN '15-30'
                        ELSE '30+'
                    END                              AS bucket,
                    COUNT(*)                         AS manifests,
                    COALESCE(SUM(difference), 0)     AS saldo,
                    COALESCE(SUM(total_to_deposit),0) AS cartera
                ")
                ->groupBy('bucket')
                ->get()
                ->keyBy('bucket');

            $buckets = ['0-7', '8-14', '15-30', '30+'];
            $result  = [];
            foreach ($buckets as $b) {
                $row        = $rows[$b] ?? null;
                $result[$b] = [
                    'manifests' => $row ? (int)   $row->manifests : 0,
                    'saldo'     => $row ? (float)  $row->saldo     : 0.0,
                    'cartera'   => $row ? (float)  $row->cartera   : 0.0,
                ];
            }

            return $result;
        });

        $fmt  = fn(float $v): string => 'L. ' . number_format($v, 2);
        $desc = fn(array $b): string =>
            "{$b['manifests']} " . ($b['manifests'] === 1 ? 'manifiesto' : 'manifiestos') .
            " — saldo: " . $fmt($b['saldo']);

        return [
            Stat::make('Corriente (0 – 7 días)', $fmt($aging['0-7']['cartera']))
                ->description($desc($aging['0-7']))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($aging['0-7']['saldo'] > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Seguimiento (8 – 14 días)', $fmt($aging['8-14']['cartera']))
                ->description($desc($aging['8-14']))
                ->descriptionIcon('heroicon-m-eye')
                ->color($aging['8-14']['saldo'] > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-clock'),

            Stat::make('Vencida (15 – 30 días)', $fmt($aging['15-30']['cartera']))
                ->description($desc($aging['15-30']))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($aging['15-30']['saldo'] > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make('Crítica (30+ días)', $fmt($aging['30+']['cartera']))
                ->description($desc($aging['30+']))
                ->descriptionIcon('heroicon-m-fire')
                ->color($aging['30+']['saldo'] > 0 ? 'danger' : 'gray')
                ->icon('heroicon-o-fire'),
        ];
    }
}
