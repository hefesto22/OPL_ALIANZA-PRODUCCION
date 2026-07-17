<?php

namespace App\Filament\Resources\Manifests\Tables;

use App\Exports\ManifestsExport;
use App\Filament\Resources\Manifests\ManifestResource;
use App\Jobs\NotifyExportReady;
use App\Models\Manifest;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\WarehouseScope;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;

class ManifestsTable
{
    public static function configure(Table $table): Table
    {
        // Decisión arquitectural: el listado NO muestra summary agregado al pie.
        //
        // Razones:
        //  1. Sum::make() reutilizado vía array compartido replicaba el mismo
        //     valor en todas las columnas (singleton trampa de Filament).
        //     Arreglarlo requeriría 5 instancias separadas → 5 queries SUM
        //     por page render, costoso a escala (3.6M filas).
        //  2. Las columnas money usan state() custom que lee del slice
        //     warehouseTotals — Sum() en SQL agrega la columna DB directa,
        //     causando inconsistencia "fila slice vs summary global" para
        //     warehouse users.
        //  3. Los totales agregados son una "vista ejecutiva" — pertenecen
        //     a un Widget de KPIs separado (con cache + visibilidad por rol),
        //     no a la tabla operacional.
        //
        // Si surge demanda, agregar StatsOverview widget arriba del listado.
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

                // Antes mostraba warehouse.code de la columna del manifest,
                // pero por diseño esa columna es nullable (un manifest agrupa
                // facturas de varias bodegas) — siempre se renderizaba "—".
                //
                // Ahora extrae los códigos únicos desde warehouseTotals
                // (eager-loaded en getEloquentQuery), uno por bodega presente.
                //
                // Tope visual: máximo 3 badges + "+N" neutro si hay más.
                // Tooltip muestra la lista completa al hover. Aguanta hasta
                // 100 bodegas sin que la fila crezca verticalmente.
                TextColumn::make('warehouses')
                    ->label('Bodegas')
                    ->state(function (Manifest $record): array {
                        $codes = $record->warehouseTotals
                            ->pluck('warehouse.code')
                            ->filter()
                            ->unique()
                            ->sort()
                            ->values();

                        return $codes->count() > 3
                            ? $codes->take(3)->push('+'.($codes->count() - 3))->all()
                            : $codes->all();
                    })
                    ->tooltip(function (Manifest $record): ?string {
                        $codes = $record->warehouseTotals
                            ->pluck('warehouse.code')
                            ->filter()
                            ->unique()
                            ->sort();

                        // Tooltip solo cuando hay overflow — evita ruido
                        // visual en el caso típico de 1–3 bodegas.
                        return $codes->count() > 3 ? $codes->implode(', ') : null;
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'OAC' => 'info',
                        'OAO' => 'success',
                        'OAS' => 'warning',
                        default => 'gray',   // el "+N" cae acá
                    })
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
                            return (int) $record->warehouseTotals
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->sum('invoices_count');
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
                            return (int) $record->warehouseTotals
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->sum('clients_count');
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
                            return (float) $record->warehouseTotals
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->sum('total_invoices');
                        }

                        return (float) $record->total_invoices;
                    }),

                // Devoluciones: color dinámico. 0 → neutro (operación limpia),
                // >0 → danger (algo se devolvió, ojo). Antes era danger siempre
                // y mostraba "0,00 HNL" en rojo aunque fuera operación perfecta.
                // Toggleable hidden por default: la mayoría de manifests tiene
                // 0 devoluciones, mostrarla siempre roba ancho a Diferencia.
                TextColumn::make('total_returns')
                    ->label('Devoluciones')
                    ->money('HNL')
                    ->color(fn (float $state): string => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => ! Auth::user()->hasRole('operador'))
                    ->state(function (Manifest $record): float {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (float) $record->warehouseTotals
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->sum('total_returns');
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
                            return (float) $record->warehouseTotals
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->sum('total_to_deposit');
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
                            return (float) $record->warehouseTotals
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->sum('total_deposited');
                        }

                        return (float) $record->total_deposited;
                    }),

                // ── Diferencia — icono contextual ───────────────────────────
                // Lógica refinada: el ícono ya no es solo "está cuadrado vs no",
                // sino qué estado del ciclo lo causa.
                //  - 0       → check verde: cuadrado, listo para cerrar.
                //  - >0 y no hay depósitos aún → minus gris: estado esperado
                //    (manifest recién importado, todavía no se cobra).
                //  - >0 y ya hay depósitos parciales → exclamation rojo:
                //    eso sí es señal genuina ("se cobró menos de lo esperado").
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
                            return (float) $record->warehouseTotals
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->sum('difference');
                        }

                        return (float) $record->difference;
                    })
                    ->color(function (float $state, Manifest $record): string {
                        if ($state == 0) {
                            return 'success';
                        }

                        return ((float) $record->total_deposited) > 0
                            ? 'danger'    // diferencia con depósitos parciales: alerta real
                            : 'gray';     // diferencia sin depósitos: estado esperado
                    })
                    ->icon(function (float $state, Manifest $record): string {
                        if ($state == 0) {
                            return 'heroicon-o-check-circle';
                        }

                        return ((float) $record->total_deposited) > 0
                            ? 'heroicon-o-exclamation-circle'
                            : 'heroicon-o-minus-circle';
                    }),

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
                // El manifiesto agrupa facturas de varias bodegas, así que la
                // columna directa `manifests.warehouse_id` es NULL (la bodega
                // real vive en warehouseTotals, igual que la columna "Bodegas").
                // Por eso filtramos vía whereHas sobre warehouseTotals, NO sobre
                // la relación belongsTo `warehouse` (que daría siempre cero).
                SelectFilter::make('warehouse_id')
                    ->label('Bodega')
                    ->options(fn (): array => Warehouse::query()
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $q, $warehouseId): Builder => $q->whereHas(
                                'warehouseTotals',
                                fn (Builder $wt): Builder => $wt->where('warehouse_id', $warehouseId)
                            )
                        );
                    })
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

                        return $record->isReadyToClose() && $user->can('close', $record);
                    })
                    ->action(function (Manifest $record): void {
                        app(\App\Services\ManifestService::class)
                            ->closeManifest($record, Auth::id());

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

                        return $record->isClosed() && $user->can('reopen', $record);
                    })
                    ->action(function (Manifest $record): void {
                        app(\App\Services\ManifestService::class)
                            ->reopenManifest($record);

                        Notification::make()
                            ->title("Manifiesto #{$record->number} reabierto.")
                            ->warning()
                            ->send();
                    }),
            ])

            ->toolbarActions([
                // NOTA: los reportes NO usan deselectRecordsAfterCompletion()
                // a propósito — mismo criterio que las facturas (2026-07-03):
                // el usuario genera varios reportes con la MISMA selección.
                BulkActionGroup::make([
                    // ── Reportes de manifiestos SELECCIONADOS ──────────
                    // Mismos permisos custom que el grupo "Reportes" del
                    // header (CustomPermissionSeeder). El payload lleva los
                    // IDs marcados + bodegas del usuario; el controlador
                    // aplica ambos filtros (PrintReportsController::
                    // applyManifestPayloadFilters).
                    BulkAction::make('report_pdf_seleccionados')
                        ->label('Ver Reporte PDF')
                        ->icon('heroicon-o-document-text')
                        ->color('danger')
                        ->visible(fn (): bool => Auth::user()->can('ReportPdf:Manifest'))
                        ->action(function (Collection $records, Component $livewire): void {
                            $payload = Crypt::encryptString(json_encode([
                                'manifest_ids' => $records->pluck('id')->all(),
                                'warehouse_ids' => WarehouseScope::getWarehouseIds(),
                            ]));

                            $livewire->js("window.open('/imprimir/reportes/manifiestos?payload=".urlencode($payload)."', '_blank')");
                        }),

                    BulkAction::make('report_sin_isv_seleccionados')
                        ->label('Ver Reporte Sin ISV')
                        ->icon('heroicon-o-document-minus')
                        ->color('warning')
                        ->visible(fn (): bool => Auth::user()->can('ReportPdfSinIsv:Manifest'))
                        ->action(function (Collection $records, Component $livewire): void {
                            $payload = Crypt::encryptString(json_encode([
                                'manifest_ids' => $records->pluck('id')->all(),
                                'warehouse_ids' => WarehouseScope::getWarehouseIds(),
                            ]));

                            $livewire->js("window.open('/imprimir/reportes/manifiestos-sin-isv?payload=".urlencode($payload)."', '_blank')");
                        }),

                    BulkAction::make('export_excel_seleccionados')
                        ->label('Exportar Excel')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->visible(fn (): bool => Auth::user()->can('ExportExcel:Manifest'))
                        ->action(function (Collection $records): void {
                            $fileName = 'manifiestos_seleccionados_'.now()->format('Y-m-d_His').'.xlsx';
                            $filePath = "exports/{$fileName}";

                            // WarehouseScope/Auth se capturan ACÁ (donde hay
                            // sesión) — dentro del worker Auth::user() es null.
                            (new ManifestsExport(
                                warehouseIds: WarehouseScope::getWarehouseIds(),
                                manifestIds: $records->pluck('id')->all(),
                            ))->queue($filePath, 'local')->chain([
                                (new NotifyExportReady(
                                    userId: Auth::id(),
                                    filePath: $filePath,
                                    fileName: $fileName,
                                ))->onQueue('high'),
                            ]);

                            Notification::make()
                                ->title('Exportación en proceso')
                                ->body("El archivo {$fileName} se está generando. Te notificaremos cuando esté listo.")
                                ->info()
                                ->send();
                        }),

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
