<?php

namespace App\Filament\Resources\Manifests\RelationManagers;

use App\Filament\Resources\Manifests\Pages\Actions\RegistrarDevolucionAction;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\User;
use App\Services\InvoicePdfService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\On;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Facturas';

    protected static ?string $label = 'Factura';

    protected static ?string $pluralLabel = 'Facturas';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-document-text';

    /**
     * Contador de la pestaña: las facturas que el usuario ve en la tabla
     * (solo las de su bodega si es usuario de bodega).
     */
    public static function getBadge(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Manifest $manifest */
        $manifest = $ownerRecord;
        /** @var User|null $user */
        $user = Auth::user();
        $query = $manifest->invoices();

        if ($user?->isWarehouseUser()) {
            $query->whereIn('warehouse_id', $user->warehouseIds());
        }

        return (string) $query->count();
    }

    public static function getBadgeColor(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    public array $statusesFilter = [];

    #[On('filterInvoicesByStatus')]
    public function applyStatusFilter(array $statuses): void
    {
        $this->statusesFilter = $statuses;
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                // La tabla ya viene ordenada por ruta: es la primera pista
                // del operador para ubicarse.
                TextColumn::make('route_number')
                    ->label('Ruta')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('invoice_number')
                    ->label('Factura')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Número copiado')
                    ->description(fn (Invoice $record): string => $record->invoice_date?->format('d/m/Y') ?? '')
                    ->toggleable(),

                TextColumn::make('client_name')
                    ->label('Cliente')
                    ->searchable(['client_name', 'client_id'])
                    ->weight('medium')
                    ->limit(34)
                    ->tooltip(fn (Invoice $record): string => trim($record->client_name.' · '.$record->address, ' ·'))
                    ->description(fn (Invoice $record): ?string => filled($record->client_id) ? 'Cód. '.$record->client_id : null)
                    ->toggleable(),

                // Solo en manifiestos de varias bodegas: en los de una sola,
                // la misma etiqueta repetida en cada fila no dice nada.
                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'OAC' => 'info',
                        'OAO' => 'success',
                        'OAS' => 'warning',
                        'OAI' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->sortable()
                    ->visible(fn (): bool => $this->getOwnerRecord()->warehouseTotals->count() > 1)
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('total_returns')
                    ->label('Devuelto')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable()
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                // Lo que la factura sigue valiendo después de devoluciones.
                TextColumn::make('a_cobrar')
                    ->label('A cobrar')
                    ->state(fn (Invoice $record): float => (float) $record->total - (float) $record->total_returns)
                    ->money('HNL')
                    ->alignEnd()
                    ->weight('bold')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'imported' => 'success',
                        'partial_return' => 'warning',
                        'returned' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state) => match ($state) {
                        'imported' => 'heroicon-o-check-circle',
                        'partial_return', 'returned' => 'heroicon-o-arrow-uturn-left',
                        'rejected' => 'heroicon-o-no-symbol',
                        default => null,
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'imported' => 'Importada',
                        'partial_return' => 'Dev. parcial',
                        'returned' => 'Devuelta',
                        'rejected' => 'Rechazada',
                        default => $state,
                    })
                    ->toggleable(),

                // ── Ocultas por defecto, activables con ⊞ ──────────────
                TextColumn::make('invoice_date')
                    ->label('Fecha factura')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('address')
                    ->label('Dirección')
                    ->limit(35)
                    ->tooltip(fn ($record) => $record->address)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'imported' => 'Importada',
                        'partial_return' => 'Dev. Parcial',
                        'returned' => 'Devuelta',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('Almacén')
                    ->relationship('warehouse', 'code')
                    ->visible(function (): bool {
                        /** @var User $user */
                        $user = Auth::user();

                        return $user->isGlobalUser();
                    }),

                SelectFilter::make('route_number')
                    ->label('Ruta')
                    // Multi-selección: la operación arma impresiones de 1 o más
                    // rutas a la vez (filtran 2 rutas → "Selecciona todos" toma
                    // solo las facturas de esas rutas → imprimen de un tiro).
                    ->multiple()
                    ->options(function (): array {
                        /** @var User $user */
                        $user = Auth::user();

                        $query = Invoice::where('manifest_id', $this->getOwnerRecord()->id);

                        if ($user->isWarehouseUser()) {
                            $query->whereIn('warehouse_id', $user->warehouseIds());
                        }

                        return $query->distinct()
                            ->orderBy('route_number')
                            ->pluck('route_number', 'route_number')
                            ->toArray();
                    }),
            ])
            ->toolbarActions([
                // NOTA: ninguna acción usa deselectRecordsAfterCompletion() a
                // propósito — la operación imprime varias cosas (facturas,
                // sublistas) con la MISMA selección; deseleccionar tras cada
                // botón los obligaba a volver a marcar todo (pedido 2026-07-03).
                BulkActionGroup::make([
                    BulkAction::make('imprimir_seleccionadas')
                        ->label('Imprimir Facturas')
                        ->icon('heroicon-o-printer')
                        ->color('info')
                        // Solo super_admin por ahora: la operación usa el
                        // Formato Hosana; este formato quedará para el futuro.
                        ->visible(fn (): bool => Auth::user()->hasRole('super_admin'))
                        ->action(function (Collection $records): void {
                            /** @var \App\Models\Manifest $manifest */
                            $manifest = $this->getOwnerRecord();
                            $invoiceIds = $records->pluck('id')->toArray();

                            $url = app(InvoicePdfService::class)
                                ->generatePrintUrl($manifest, $invoiceIds);

                            $this->js("window.open('{$url}', '_blank')");
                        }),

                    BulkAction::make('imprimir_formato_hosana')
                        ->label('Imprimir (Formato Hosana)')
                        ->icon('heroicon-o-printer')
                        ->color('success')
                        ->action(function (Collection $records): void {
                            /** @var \App\Models\Manifest $manifest */
                            $manifest = $this->getOwnerRecord();

                            $payload = Crypt::encryptString(json_encode([
                                'manifest_id' => $manifest->id,
                                'invoice_ids' => $records->pluck('id')->toArray(),
                            ]));

                            $url = route('invoices.print.hosana', ['payload' => $payload]);
                            $this->js("window.open('{$url}', '_blank')");
                        }),

                    BulkAction::make('sublista_productos_seleccionadas')
                        ->label('Sublista Productos')
                        ->icon('heroicon-o-cube')
                        ->color('warning')
                        ->action(function (Collection $records): void {
                            /** @var \App\Models\Manifest $manifest */
                            $manifest = $this->getOwnerRecord();

                            $payloadData = [
                                'manifest_id' => $manifest->id,
                                'invoice_ids' => $records->pluck('id')->toArray(),
                            ];

                            $payload = Crypt::encryptString(json_encode($payloadData));
                            $this->js("window.open('/imprimir/reportes/productos?payload=".urlencode($payload)."', '_blank')");
                        }),

                    BulkAction::make('sublista_facturas_seleccionadas')
                        ->label('Sublista Facturas')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->color('success')
                        ->action(function (Collection $records): void {
                            /** @var \App\Models\Manifest $manifest */
                            $manifest = $this->getOwnerRecord();

                            $payloadData = [
                                'manifest_id' => $manifest->id,
                                'invoice_ids' => $records->pluck('id')->toArray(),
                            ];

                            $payload = Crypt::encryptString(json_encode($payloadData));
                            $this->js("window.open('/imprimir/reportes/facturas-checklist?payload=".urlencode($payload)."', '_blank')");
                        }),
                ]),
            ])
            ->recordActions([
                RegistrarDevolucionAction::make(),
            ])
            ->modifyQueryUsing(function (Builder $query): void {
                /** @var User $user */
                $user = Auth::user();

                $query->with(['warehouse:id,code']);

                if ($user->isWarehouseUser()) {
                    $query->whereIn('warehouse_id', $user->warehouseIds());
                }

                if (! empty($this->statusesFilter)) {
                    $query->whereIn('status', $this->statusesFilter);
                }
            })
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('Sin facturas')
            ->emptyStateDescription('No hay facturas asignadas a este manifiesto.')

            ->defaultSort('route_number', 'asc')
            ->striped()
            ->searchPlaceholder('Buscar cliente, factura o ruta')
            ->paginated([25, 50, 100, 200]);
    }
}
