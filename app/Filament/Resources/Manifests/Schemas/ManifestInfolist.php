<?php

namespace App\Filament\Resources\Manifests\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ManifestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            // ══════════════════════════════════════════════════════════════
            // FILA 1: Información del Manifiesto — ancho completo
            // ══════════════════════════════════════════════════════════════
            Section::make('Información del Manifiesto')
                ->icon('heroicon-o-rectangle-stack')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('number')
                        ->label('# Manifiesto')
                        ->weight('bold')
                        ->copyable()
                        ->copyMessage('Número copiado'),

                    TextEntry::make('date')
                        ->label('Fecha')
                        ->date('d/m/Y'),

                    TextEntry::make('status')
                        ->label('Estado')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending'    => 'gray',
                            'processing' => 'warning',
                            'imported'   => 'info',
                            'closed'     => 'success',
                            default      => 'gray',
                        })
                        ->icon(fn (string $state): string => match ($state) {
                            'pending'    => 'heroicon-o-clock',
                            'processing' => 'heroicon-o-arrow-path',
                            'imported'   => 'heroicon-o-inbox-arrow-down',
                            'closed'     => 'heroicon-o-lock-closed',
                            default      => 'heroicon-o-question-mark-circle',
                        })
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'pending'    => 'Pendiente',
                            'processing' => 'Procesando',
                            'imported'   => 'Importado',
                            'closed'     => 'Cerrado',
                            default      => $state,
                        }),

                    TextEntry::make('supplier.name')
                        ->label('Proveedor')
                        ->columnSpan(2),

                    TextEntry::make('warehouse.code')
                        ->label('Bodega Principal')
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'OAC'   => 'info',
                            'OAO'   => 'success',
                            'OAS'   => 'warning',
                            default => 'gray',
                        })
                        ->placeholder('—'),
                ]),

            // Solo visible cuando el manifiesto está cerrado —
            // no contamina la pantalla con campos vacíos en estado abierto.
            Section::make('Información de Cierre')
                ->icon('heroicon-o-lock-closed')
                ->columns(2)
                ->columnSpanFull()
                ->hidden(fn ($record): bool => ! $record->isClosed())
                ->schema([
                    TextEntry::make('closedBy.name')
                        ->label('Cerrado por')
                        ->placeholder('—'),

                    TextEntry::make('closed_at')
                        ->label('Fecha y Hora de Cierre')
                        ->dateTime('d/m/Y H:i'),
                ]),

            // ══════════════════════════════════════════════════════════════
            // FILA 2: Resumen de Facturas (izq.) + Estadísticas (der.)
            // Ambas secciones comparten la misma fila visual para aprovechar
            // el ancho y establecer una relación visual entre los datos.
            // ══════════════════════════════════════════════════════════════
            Grid::make(2)
                ->columnSpanFull()
                ->schema([
                    // ── Izquierda: tarjetas clickeables de facturas ────────
                    Section::make('Resumen de Facturas')
                        ->icon('heroicon-o-document-text')
                        ->columns(2)
                        ->extraAttributes(['class' => 'h-full'])
                        ->visible(fn (): bool => Auth::user()->isGlobalUser())
                        ->schema([
                            TextEntry::make('invoices_count')
                                ->label('Total Enviadas')
                                ->weight('bold')
                                ->suffix(' facturas')
                                ->state(fn ($record) => $record->invoices()->count())
                                ->extraAttributes([
                                    'class'      => 'cursor-pointer select-none rounded-lg px-3 py-2 transition-all hover:bg-gray-100 dark:hover:bg-white/5',
                                    'wire:click' => "\$dispatch('filterInvoicesByStatus', { statuses: [] })",
                                    'title'      => 'Ver todas las facturas',
                                ]),

                            TextEntry::make('invoices_summary_accepted')
                                ->label('Aceptadas')
                                ->weight('bold')
                                ->suffix(' facturas')
                                ->color('success')
                                ->state(function ($record): int {
                                    $summary = $record->getInvoicesSummary();
                                    return ($summary['imported']['count'] ?? 0)
                                         + ($summary['partial_return']['count'] ?? 0)
                                         + ($summary['returned']['count'] ?? 0);
                                })
                                ->extraAttributes([
                                    'class'      => 'cursor-pointer select-none rounded-lg px-3 py-2 transition-all hover:bg-success-50 dark:hover:bg-success-500/10',
                                    'wire:click' => "\$dispatch('filterInvoicesByStatus', { statuses: ['imported','partial_return','returned'] })",
                                    'title'      => 'Ver facturas aceptadas',
                                ]),

                            TextEntry::make('invoices_summary_pending')
                                ->label('Pend. Revisión')
                                ->weight('bold')
                                ->suffix(' facturas')
                                ->color('warning')
                                ->state(fn ($record): int => $record->getInvoicesSummary()['pending_warehouse']['count'] ?? 0)
                                ->extraAttributes([
                                    'class'      => 'cursor-pointer select-none rounded-lg px-3 py-2 transition-all hover:bg-warning-50 dark:hover:bg-warning-500/10',
                                    'wire:click' => "\$dispatch('filterInvoicesByStatus', { statuses: ['pending_warehouse'] })",
                                    'title'      => 'Ver facturas pendientes de revisión',
                                ]),

                            TextEntry::make('invoices_summary_rejected')
                                ->label('Rechazadas')
                                ->weight('bold')
                                ->suffix(' facturas')
                                ->color('danger')
                                ->state(fn ($record): int => $record->getInvoicesSummary()['rejected']['count'] ?? 0)
                                ->extraAttributes([
                                    'class'      => 'cursor-pointer select-none rounded-lg px-3 py-2 transition-all hover:bg-danger-50 dark:hover:bg-danger-500/10',
                                    'wire:click' => "\$dispatch('filterInvoicesByStatus', { statuses: ['rejected'] })",
                                    'title'      => 'Ver facturas rechazadas',
                                ]),
                        ]),

                    // ── Derecha: estadísticas operativas ──────────────────
                    Section::make('Estadísticas')
                        ->icon('heroicon-o-chart-bar')
                        ->columns(2)
                        ->extraAttributes(['class' => 'h-full'])
                        ->schema([
                            TextEntry::make('invoices_count')
                                ->label('Facturas con bodega asignada')
                                ->suffix(fn ($record) => ' de ' . $record->invoices()->count() . ' enviadas')
                                ->state(fn ($record): int => (int) $record->invoices_count),

                            TextEntry::make('returns_count')
                                ->label('Total Devoluciones')
                                ->state(fn ($record): int => (int) $record->returns_count)
                                ->color(fn ($state): string => $state > 0 ? 'warning' : 'gray'),

                            TextEntry::make('deposit_progress')
                                ->label('Progreso de Depósito')
                                ->columnSpanFull()
                                ->state(function ($record): string {
                                    $toDeposit = (float) $record->total_to_deposit;
                                    $deposited = (float) $record->total_deposited;
                                    if ($toDeposit <= 0) return 'Sin monto a depositar';
                                    $pct = min(100, round(($deposited / $toDeposit) * 100, 1));
                                    return "{$pct}%";
                                })
                                ->color(function ($record): string {
                                    $toDeposit = (float) $record->total_to_deposit;
                                    $deposited = (float) $record->total_deposited;
                                    if ($toDeposit <= 0) return 'gray';
                                    $pct = ($deposited / $toDeposit) * 100;
                                    if ($pct >= 100) return 'success';
                                    if ($pct >= 50)  return 'warning';
                                    return 'danger';
                                }),
                        ]),
                ]),

            // ══════════════════════════════════════════════════════════════
            // FILA 3: Resumen Financiero — ancho completo, una sola fila
            // Al ocupar el 100% del ancho, 5 columnas tienen suficiente
            // espacio para mostrar cualquier monto sin romper en dos líneas.
            // ══════════════════════════════════════════════════════════════
            Section::make('Resumen Financiero')
                ->icon('heroicon-o-banknotes')
                ->description('Total − Devoluciones = A Depositar   ·   A Depositar − Depositado = Diferencia')
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('total_invoices')
                        ->label('Total Manifiesto')
                        ->money('HNL')
                        ->weight('bold')
                        ->state(function ($record): float {
                            /** @var User $user */
                            $user = Auth::user();
                            if ($user->isWarehouseUser()) {
                                return (float) ($record->warehouseTotals
                                    ->where('warehouse_id', $user->warehouse_id)
                                    ->first()?->total_invoices ?? 0);
                            }
                            return (float) $record->total_invoices;
                        }),

                    TextEntry::make('total_returns')
                        ->label('(−) Devoluciones')
                        ->money('HNL')
                        ->color('danger')
                        ->state(function ($record): float {
                            /** @var User $user */
                            $user = Auth::user();
                            if ($user->isWarehouseUser()) {
                                return (float) ($record->warehouseTotals
                                    ->where('warehouse_id', $user->warehouse_id)
                                    ->first()?->total_returns ?? 0);
                            }
                            return (float) $record->total_returns;
                        }),

                    TextEntry::make('total_to_deposit')
                        ->label('(=) A Depositar')
                        ->money('HNL')
                        ->color('warning')
                        ->weight('bold')
                        ->state(function ($record): float {
                            /** @var User $user */
                            $user = Auth::user();
                            if ($user->isWarehouseUser()) {
                                return (float) ($record->warehouseTotals
                                    ->where('warehouse_id', $user->warehouse_id)
                                    ->first()?->total_to_deposit ?? 0);
                            }
                            return (float) $record->total_to_deposit;
                        }),

                    TextEntry::make('total_deposited')
                        ->label('(−) Depositado')
                        ->money('HNL')
                        ->color('success')
                        ->state(function ($record): float {
                            /** @var User $user */
                            $user = Auth::user();
                            if ($user->isWarehouseUser()) {
                                return (float) ($record->warehouseTotals
                                    ->where('warehouse_id', $user->warehouse_id)
                                    ->first()?->total_deposited ?? 0);
                            }
                            return (float) $record->total_deposited;
                        }),

                    TextEntry::make('difference')
                        ->label('(=) Diferencia')
                        ->money('HNL')
                        ->weight('bold')
                        ->state(function ($record): float {
                            /** @var User $user */
                            $user = Auth::user();
                            if ($user->isWarehouseUser()) {
                                return (float) ($record->warehouseTotals
                                    ->where('warehouse_id', $user->warehouse_id)
                                    ->first()?->difference ?? 0);
                            }
                            return (float) $record->difference;
                        })
                        ->color(fn ($state): string => ($state ?? 1) == 0 ? 'success' : 'danger')
                        ->icon(fn ($state): string => ($state ?? 1) == 0
                            ? 'heroicon-o-check-circle'
                            : 'heroicon-o-exclamation-circle'
                        ),
                ]),
        ]);
    }
}
