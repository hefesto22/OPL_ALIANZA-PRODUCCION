<?php

namespace App\Filament\Resources\Manifests\Tables;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\Manifest;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ManifestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // ── Identificación ───────────────────────────────────────
                TextColumn::make('number')
                    ->label('# Manifiesto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Número copiado'),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'OAC' => 'info',
                        'OAO' => 'success',
                        'OAS' => 'warning',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                // ── Facturas + Clientes ───────────────────────────────────
                TextColumn::make('invoices_count')
                    ->label('Facturas')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable()
                    ->state(function (Manifest $record): int {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (int) ($record->warehouseTotals
                                ->where('warehouse_id', $user->warehouse_id)
                                ->first()?->invoices_count ?? 0);
                        }

                        return (int) $record->invoices_count;
                    }),

                // Clientes únicos por client_id (ID interno de Jaremar).
                // Un cliente puede tener varias facturas en el mismo manifiesto
                // — esta columna muestra cuántos clientes distintos hay.
                TextColumn::make('clients_count')
                    ->label('Clientes')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable()
                    ->badge()
                    ->color('info')
                    ->state(function (Manifest $record): int {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (int) ($record->warehouseTotals
                                ->where('warehouse_id', $user->warehouse_id)
                                ->first()?->clients_count ?? 0);
                        }

                        return (int) $record->clients_count;
                    }),

                // ── Columnas monetarias — toggleables en móvil ───────────
                TextColumn::make('total_invoices')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable()
                    ->state(function (Manifest $record): float {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (float) ($record->warehouseTotals
                                ->where('warehouse_id', $user->warehouse_id)
                                ->first()?->total_invoices ?? 0);
                        }

                        return (float) $record->total_invoices;
                    }),

                TextColumn::make('total_returns')
                    ->label('Devoluciones')
                    ->money('HNL')
                    ->color('danger')
                    ->toggleable()
                    ->visible(fn (): bool => ! Auth::user()->hasRole('operador'))
                    ->state(function (Manifest $record): float {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (float) ($record->warehouseTotals
                                ->where('warehouse_id', $user->warehouse_id)
                                ->first()?->total_returns ?? 0);
                        }

                        return (float) $record->total_returns;
                    }),

                TextColumn::make('total_to_deposit')
                    ->label('A Depositar')
                    ->money('HNL')
                    ->color('warning')
                    ->weight('bold')
                    ->toggleable()
                    ->visible(fn (): bool => ! Auth::user()->hasRole('operador'))
                    ->state(function (Manifest $record): float {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (float) ($record->warehouseTotals
                                ->where('warehouse_id', $user->warehouse_id)
                                ->first()?->total_to_deposit ?? 0);
                        }

                        return (float) $record->total_to_deposit;
                    }),

                TextColumn::make('total_deposited')
                    ->label('Depositado')
                    ->money('HNL')
                    ->color('success')
                    ->toggleable()
                    ->visible(fn (): bool => ! Auth::user()->hasRole('operador'))
                    ->state(function (Manifest $record): float {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (float) ($record->warehouseTotals
                                ->where('warehouse_id', $user->warehouse_id)
                                ->first()?->total_deposited ?? 0);
                        }

                        return (float) $record->total_deposited;
                    }),

                // ── Diferencia — icono + color: la columna más importante ─
                // Usar ícono además de color para no depender solo del
                // semáforo (accesibilidad y legibilidad en impresión).
                TextColumn::make('difference')
                    ->label('Diferencia')
                    ->money('HNL')
                    ->weight('bold')
                    ->toggleable()
                    ->visible(fn (): bool => ! Auth::user()->hasRole('operador'))
                    ->state(function (Manifest $record): float {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (float) ($record->warehouseTotals
                                ->where('warehouse_id', $user->warehouse_id)
                                ->first()?->difference ?? 0);
                        }

                        return (float) $record->difference;
                    })
                    ->color(fn (float $state): string => $state == 0 ? 'success' : 'danger')
                    ->icon(fn (float $state): string => $state == 0
                        ? 'heroicon-o-check-circle'
                        : 'heroicon-o-exclamation-circle'
                    ),

                // ── Estado — siempre visible, es lo primero que busca el ojo ─
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'imported' => 'info',
                        'closed' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'pending' => 'heroicon-o-clock',
                        'processing' => 'heroicon-o-arrow-path',
                        'imported' => 'heroicon-o-inbox-arrow-down',
                        'closed' => 'heroicon-o-lock-closed',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pendiente',
                        'processing' => 'Procesando',
                        'imported' => 'Importado',
                        'closed' => 'Cerrado',
                        default => $state,
                    }),
            ])

            ->filters([
                // ── Filtro de período ─────────────────────────────────────
                // Opciones predefinidas para los casos más frecuentes +
                // fecha exacta y rango personalizado para casos puntuales.
                // Filtra sobre el campo `date` del manifiesto (fecha de Jaremar).
                Filter::make('period')
                    ->label('Período')
                    ->form([
                        Select::make('period_type')
                            ->label('Período')
                            ->placeholder('Todos los manifiestos')
                            ->options([
                                'today' => 'Hoy',
                                'week' => 'Esta semana',
                                'month' => 'Este mes',
                                'date' => 'Fecha específica',
                                'custom' => 'Rango personalizado',
                            ])
                            ->live(),

                        DatePicker::make('specific_date')
                            ->label('Fecha')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->visible(fn (Get $get) => $get('period_type') === 'date'),

                        DatePicker::make('date_from')
                            ->label('Desde')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->visible(fn (Get $get) => $get('period_type') === 'custom'),

                        DatePicker::make('date_to')
                            ->label('Hasta')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->visible(fn (Get $get) => $get('period_type') === 'custom'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['period_type'] ?? null) {
                            'today' => $query->whereDate('date', today()),
                            'week' => $query->whereBetween('date', [
                                now()->startOfWeek(),
                                now()->endOfWeek(),
                            ]),
                            'month' => $query->whereYear('date', now()->year)
                                ->whereMonth('date', now()->month),
                            'date' => $query->when(
                                $data['specific_date'] ?? null,
                                fn ($q, $v) => $q->whereDate('date', $v)
                            ),
                            'custom' => $query
                                ->when(
                                    $data['date_from'] ?? null,
                                    fn ($q, $v) => $q->whereDate('date', '>=', $v)
                                )
                                ->when(
                                    $data['date_to'] ?? null,
                                    fn ($q, $v) => $q->whereDate('date', '<=', $v)
                                ),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): ?string {
                        return match ($data['period_type'] ?? null) {
                            'today' => 'Período: Hoy ('.today()->format('d/m/Y').')',
                            'week' => 'Período: Esta semana',
                            'month' => 'Período: '.now()->translatedFormat('F Y'),
                            'date' => isset($data['specific_date'])
                                            ? 'Fecha: '.Carbon::parse($data['specific_date'])->format('d/m/Y')
                                            : null,
                            'custom' => collect([
                                isset($data['date_from']) ? 'Desde '.Carbon::parse($data['date_from'])->format('d/m/Y') : null,
                                isset($data['date_to']) ? 'hasta '.Carbon::parse($data['date_to'])->format('d/m/Y') : null,
                            ])->filter()->join(' ') ?: null,
                            default => null,
                        };
                    }),

                // ── Filtro de bodega (solo usuarios globales) ─────────────
                SelectFilter::make('warehouse_id')
                    ->label('Bodega')
                    ->relationship('warehouse', 'code')
                    ->visible(function (): bool {
                        /** @var User $user */
                        $user = Auth::user();

                        return $user->isGlobalUser();
                    }),
            ])

            // ── Acciones por fila ──────────────────────────────────────
            ->recordAction('view')
            ->recordUrl(fn (Manifest $record): string => ManifestResource::getUrl('view', ['record' => $record])
            )
            ->recordActions([
                ViewAction::make()
                    ->label('Ver'),

                EditAction::make()
                    ->label('Editar')
                    ->hidden(function (Manifest $record): bool {
                        /** @var User $user */
                        $user = Auth::user();

                        return $record->isClosed() || ! $user->hasRole('super_admin');
                    }),

                Action::make('close')
                    ->label('Cerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('¿Cerrar este manifiesto?')
                    ->modalDescription('Una vez cerrado no podrá modificarse. Solo un administrador podrá reabrirlo.')
                    ->modalSubmitActionLabel('Sí, cerrar')
                    ->visible(function (Manifest $record): bool {
                        /** @var User $user */
                        $user = Auth::user();

                        return $record->isReadyToClose() && $user->hasAnyRole(['super_admin', 'admin']);
                    })
                    ->action(function (Manifest $record): void {
                        $record->close(Auth::id());
                        Notification::make()
                            ->title("Manifiesto #{$record->number} cerrado correctamente.")
                            ->success()
                            ->send();
                    }),

                Action::make('reopen')
                    ->label('Reabrir')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('¿Reabrir este manifiesto?')
                    ->modalDescription('El manifiesto volverá al estado "Importado" y podrá ser modificado nuevamente.')
                    ->modalSubmitActionLabel('Sí, reabrir')
                    ->visible(function (Manifest $record): bool {
                        /** @var User $user */
                        $user = Auth::user();

                        return $record->isClosed() && $user->hasRole('super_admin');
                    })
                    ->action(function (Manifest $record): void {
                        $record->reopen();
                        Notification::make()
                            ->title("Manifiesto #{$record->number} reabierto.")
                            ->warning()
                            ->send();
                    }),
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(function (): bool {
                            /** @var User $user */
                            $user = Auth::user();

                            return $user->hasAnyRole(['super_admin', 'admin']);
                        }),
                ]),
            ])

            // ── Estado vacío ───────────────────────────────────────────
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading('No hay manifiestos')
            ->emptyStateDescription('Cuando se importe un manifiesto aparecerá aquí.')

            ->defaultSort('created_at', 'desc')
            ->striped();
    }
}
