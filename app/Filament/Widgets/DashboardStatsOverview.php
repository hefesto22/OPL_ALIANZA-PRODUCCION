<?php

namespace App\Filament\Widgets;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Support\WarehouseScope;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class DashboardStatsOverview extends BaseWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Clave de caché única por bodega: evita que un usuario de bodega A
        // vea los datos cacheados de la bodega B (o del admin global).
        $cacheKey = WarehouseScope::cacheKey('dashboard:stats:overview');
        $warehouseId = WarehouseScope::getWarehouseId();

        $data = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($warehouseId) {
            $activeStatuses = ['pending', 'processing', 'imported'];
            $thisMonth = now()->month;
            $thisYear = now()->year;
            $lastMonth = now()->subMonth()->month;
            $lastMonthYear = now()->subMonth()->year;

            // Helper: query base de Manifest ya filtrado por bodega si aplica
            $mq = fn () => $warehouseId
                ? Manifest::where('warehouse_id', $warehouseId)
                : Manifest::query();

            // Helper: query base de Deposit filtrado por bodega via manifest
            $dq = fn () => $warehouseId
                ? Deposit::whereHas('manifest', fn ($q) => $q->where('warehouse_id', $warehouseId))
                : Deposit::query();

            // Helper: query base de InvoiceReturn filtrado por bodega
            $rq = fn () => $warehouseId
                ? InvoiceReturn::where('warehouse_id', $warehouseId)
                : InvoiceReturn::query();

            // Helper: query base de Invoice filtrado por bodega
            $iq = fn () => $warehouseId
                ? Invoice::where('warehouse_id', $warehouseId)
                : Invoice::query();

            // ── Manifiestos ────────────────────────────────────────────
            $activeManifests = $mq()->whereIn('status', $activeStatuses)->count();
            $closedManifests = $mq()->where('status', 'closed')->count();

            // ── Pendiente por depositar (diferencia real en manifiestos abiertos)
            $pendingToDeposit = (float) $mq()->whereIn('status', $activeStatuses)
                ->sum('difference');

            // ── Venta neta mes actual vs anterior ─────────────────────
            $ventaNetaMes = (float) $mq()
                ->whereMonth('date', $thisMonth)
                ->whereYear('date', $thisYear)
                ->sum('total_to_deposit');

            $ventaNetaMesAnterior = (float) $mq()
                ->whereMonth('date', $lastMonth)
                ->whereYear('date', $lastMonthYear)
                ->sum('total_to_deposit');

            // ── Devoluciones del mes ───────────────────────────────────
            $devolucionesMes = (float) $mq()
                ->whereMonth('date', $thisMonth)
                ->whereYear('date', $thisYear)
                ->sum('total_returns');

            $totalFacturadoMes = (float) $mq()
                ->whereMonth('date', $thisMonth)
                ->whereYear('date', $thisYear)
                ->sum('total_invoices');

            // ── Depositado este mes vs anterior ────────────────────────
            $depositadoMes = (float) $dq()
                ->whereMonth('deposit_date', $thisMonth)
                ->whereYear('deposit_date', $thisYear)
                ->sum('amount');

            $depositadoMesAnterior = (float) $dq()
                ->whereMonth('deposit_date', $lastMonth)
                ->whereYear('deposit_date', $lastMonthYear)
                ->sum('amount');

            // ── Devoluciones pendientes de aprobación ──────────────────
            $devolucionesPendientes = $rq()->where('status', 'pending')->count();

            // ── Clientes únicos facturados este mes ────────────────────
            $clientesMes = $iq()
                ->whereMonth('invoice_date', $thisMonth)
                ->whereYear('invoice_date', $thisYear)
                ->whereNotNull('client_id')
                ->distinct('client_id')
                ->count('client_id');

            // ── DSO: Días Promedio de Cobro (últimos 90 días de cierres) ──
            $dso = (float) $mq()
                ->where('status', 'closed')
                ->whereNotNull('closed_at')
                ->where('closed_at', '>=', now()->subDays(90))
                ->selectRaw(
                    'AVG(EXTRACT(EPOCH FROM (closed_at - date::timestamptz)) / 86400.0) as dso'
                )
                ->value('dso') ?? 0;

            // ── Eficiencia de Cobro Global (manifiestos activos) ───────
            $totalActivo = (float) $mq()->whereIn('status', $activeStatuses)->sum('total_to_deposit');
            $cobradoActivo = (float) $mq()->whereIn('status', $activeStatuses)->sum('total_deposited');
            $eficienciaCobro = $totalActivo > 0
                ? round(($cobradoActivo / $totalActivo) * 100, 1)
                : 100.0;

            return compact(
                'activeManifests', 'closedManifests',
                'pendingToDeposit',
                'ventaNetaMes', 'ventaNetaMesAnterior',
                'devolucionesMes', 'totalFacturadoMes',
                'depositadoMes', 'depositadoMesAnterior',
                'devolucionesPendientes',
                'clientesMes',
                'dso',
                'eficienciaCobro',
                'totalActivo',
            );
        });

        // ── Variaciones mes a mes ──────────────────────────────────────
        $ventaDiff = $data['ventaNetaMes'] - $data['ventaNetaMesAnterior'];
        $ventaIcon = $ventaDiff >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
        $ventaColor = $ventaDiff >= 0 ? 'success' : 'danger';
        $ventaDesc = ($ventaDiff >= 0 ? '▲ ' : '▼ ').'L. '.number_format(abs($ventaDiff), 0).' vs mes anterior';

        $depositDiff = $data['depositadoMes'] - $data['depositadoMesAnterior'];
        $depositIcon = $depositDiff >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
        $depositColor = $depositDiff >= 0 ? 'success' : 'warning';
        $depositDesc = ($depositDiff >= 0 ? '▲ ' : '▼ ').'L. '.number_format(abs($depositDiff), 0).' vs mes anterior';

        $devPct = $data['totalFacturadoMes'] > 0
            ? round(($data['devolucionesMes'] / $data['totalFacturadoMes']) * 100, 1)
            : 0;

        $dsoRound = round($data['dso'], 1);
        $dsoColor = $dsoRound <= 7 ? 'success' : ($dsoRound <= 14 ? 'warning' : 'danger');
        $dsoDesc = $dsoRound <= 7
            ? 'Cobro excelente'
            : ($dsoRound <= 14 ? 'Cobro aceptable' : 'Cobro lento — revisar');

        $efic = $data['eficienciaCobro'];
        $eficColor = $efic >= 90 ? 'success' : ($efic >= 70 ? 'warning' : 'danger');
        $eficDesc = 'de L. '.number_format($data['totalActivo'], 0).' en cartera activa';

        $mesActual = now()->locale('es')->translatedFormat('F');

        return [
            // 1 ── Manifiestos activos
            Stat::make('Manifiestos Activos', $data['activeManifests'])
                ->description("{$data['closedManifests']} cerrados en total")
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('primary')
                ->icon('heroicon-o-document-text'),

            // 2 ── Pendiente por depositar
            Stat::make('Pendiente por Depositar', 'L. '.number_format($data['pendingToDeposit'], 2))
                ->description('Saldo abierto en manifiestos activos')
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($data['pendingToDeposit'] > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-banknotes'),

            // 3 ── Venta neta del mes con tendencia
            Stat::make("Venta Neta {$mesActual}", 'L. '.number_format($data['ventaNetaMes'], 2))
                ->description($ventaDesc)
                ->descriptionIcon($ventaIcon)
                ->color($ventaColor)
                ->icon('heroicon-o-chart-bar'),

            // 4 ── Depositado este mes con tendencia
            Stat::make("Depositado {$mesActual}", 'L. '.number_format($data['depositadoMes'], 2))
                ->description($depositDesc)
                ->descriptionIcon($depositIcon)
                ->color($depositColor)
                ->icon('heroicon-o-building-library'),

            // 5 ── Devoluciones del mes con porcentaje
            Stat::make("Devoluciones {$mesActual}", 'L. '.number_format($data['devolucionesMes'], 2))
                ->description("{$devPct}% del total facturado del mes")
                ->descriptionIcon('heroicon-m-arrow-uturn-left')
                ->color($devPct > 10 ? 'danger' : ($devPct > 5 ? 'warning' : 'success'))
                ->icon('heroicon-o-arrow-uturn-left'),

            // 6 ── Clientes únicos + alerta de devoluciones pendientes
            Stat::make("Clientes {$mesActual}", number_format($data['clientesMes']))
                ->description("{$data['devolucionesPendientes']} devoluciones pendientes de aprobación")
                ->descriptionIcon($data['devolucionesPendientes'] > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($data['devolucionesPendientes'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-users'),

            // 7 ── DSO: Días promedio de cobro (últimos 90 días)
            Stat::make('DSO — Días de Cobro', $dsoRound.' días')
                ->description($dsoDesc)
                ->descriptionIcon($dsoColor === 'success' ? 'heroicon-m-check-circle' : 'heroicon-m-clock')
                ->color($dsoColor)
                ->icon('heroicon-o-calendar-days'),

            // 8 ── Eficiencia global de cobro (manifiestos activos)
            Stat::make('Eficiencia de Cobro', $efic.'%')
                ->description($eficDesc)
                ->descriptionIcon($efic >= 90 ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle')
                ->color($eficColor)
                ->icon('heroicon-o-arrow-path'),
        ];
    }
}
