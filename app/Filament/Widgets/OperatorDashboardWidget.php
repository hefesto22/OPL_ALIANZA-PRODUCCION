<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Support\WarehouseScope;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Widget exclusivo para el rol operador.
 *
 * Muestra un resumen rápido de la bodega asignada al usuario:
 *   - Manifiestos activos en su bodega
 *   - Facturas del mes actual en su bodega
 *   - Total facturado del mes en su bodega
 *   - Estado del último manifiesto
 *
 * Controlado por Shield: solo los roles con permiso
 * `widget_OperatorDashboardWidget` lo verán.
 */
class OperatorDashboardWidget extends BaseWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 1;

    public function getHeading(): ?string
    {
        $user = Auth::user();
        $warehouseName = $user->warehouse?->name ?? 'Mi Bodega';

        return "Resumen — {$warehouseName}";
    }

    protected function getStats(): array
    {
        $user        = Auth::user();
        $warehouseId = $user->warehouse_id;

        if (! $warehouseId) {
            return [
                Stat::make('Sin Bodega', 'N/A')
                    ->description('No tienes bodega asignada')
                    ->color('gray'),
            ];
        }

        $cacheKey = "dashboard:operator:warehouse:{$warehouseId}";

        $data = Cache::remember($cacheKey, now()->addMinutes(3), function () use ($warehouseId) {
            $thisMonth = now()->month;
            $thisYear  = now()->year;

            $activeStatuses = ['pending', 'processing', 'imported'];

            // Manifiestos activos con facturas en esta bodega
            $activeManifests = Manifest::whereIn('status', $activeStatuses)
                ->whereHas('invoices', fn($q) => $q->where('warehouse_id', $warehouseId))
                ->count();

            // Facturas del mes en esta bodega
            $invoicesMes = Invoice::where('warehouse_id', $warehouseId)
                ->whereMonth('invoice_date', $thisMonth)
                ->whereYear('invoice_date', $thisYear)
                ->where('status', '!=', 'rejected')
                ->count();

            // Total facturado del mes en esta bodega
            $totalFacturadoMes = (float) Invoice::where('warehouse_id', $warehouseId)
                ->whereMonth('invoice_date', $thisMonth)
                ->whereYear('invoice_date', $thisYear)
                ->where('status', '!=', 'rejected')
                ->sum('total');

            // Último manifiesto con facturas en esta bodega
            $lastManifest = Manifest::whereHas('invoices', fn($q) => $q->where('warehouse_id', $warehouseId))
                ->latest('date')
                ->first();

            $lastManifestNumber = $lastManifest?->number ?? '—';
            $lastManifestStatus = $lastManifest?->status ?? 'pending';
            $lastManifestDate   = $lastManifest?->date?->format('d/m/Y') ?? '—';

            return compact(
                'activeManifests',
                'invoicesMes',
                'totalFacturadoMes',
                'lastManifestNumber',
                'lastManifestStatus',
                'lastManifestDate',
            );
        });

        $statusLabel = match ($data['lastManifestStatus']) {
            'pending'    => 'Pendiente',
            'processing' => 'En Proceso',
            'imported'   => 'Importado',
            'closed'     => 'Cerrado',
            default      => $data['lastManifestStatus'],
        };

        $statusColor = match ($data['lastManifestStatus']) {
            'pending'    => 'gray',
            'processing' => 'info',
            'imported'   => 'warning',
            'closed'     => 'success',
            default      => 'gray',
        };

        $mesActual = now()->locale('es')->translatedFormat('F');

        return [
            Stat::make('Manifiestos Activos', $data['activeManifests'])
                ->description('Con facturas en tu bodega')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary')
                ->icon('heroicon-o-clipboard-document-list'),

            Stat::make("Facturas {$mesActual}", number_format($data['invoicesMes']))
                ->description('Facturas de tu bodega este mes')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('info')
                ->icon('heroicon-o-document-duplicate'),

            Stat::make("Facturado {$mesActual}", 'L. ' . number_format($data['totalFacturadoMes'], 2))
                ->description('Total facturado en tu bodega')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Último Manifiesto', $data['lastManifestNumber'])
                ->description("{$statusLabel} — {$data['lastManifestDate']}")
                ->descriptionIcon('heroicon-m-clock')
                ->color($statusColor)
                ->icon('heroicon-o-document-magnifying-glass'),
        ];
    }
}
