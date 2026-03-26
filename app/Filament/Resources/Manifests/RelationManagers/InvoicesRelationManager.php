<?php

namespace App\Filament\Resources\Manifests\RelationManagers;

use App\Filament\Resources\Manifests\Pages\Actions\RegistrarDevolucionAction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Illuminate\Support\Facades\Crypt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';
    protected static ?string $title       = 'Facturas';
    protected static ?string $label       = 'Factura';
    protected static ?string $pluralLabel = 'Facturas';

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
                TextColumn::make('warehouse.code')
                    ->label('Almacén')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'OAC'   => 'info',
                        'OAO'   => 'success',
                        'OAS'   => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('route_number')
                    ->label('Num. Ruta')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('invoice_date')
                    ->label('Fecha Factura')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('invoice_number')
                    ->label('# Factura')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Número copiado')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('client_id')
                    ->label('Cód. Cliente')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('client_name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->client_name)
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('address')
                    ->label('Dirección')
                    ->limit(35)
                    ->tooltip(fn ($record) => $record->address)
                    ->visibleFrom('xl')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'imported'       => 'success',
                        'partial_return' => 'info',
                        'returned'       => 'danger',
                        'rejected'       => 'gray',
                        default          => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'imported'       => 'Importada',
                        'partial_return' => 'Dev. Parcial',
                        'returned'       => 'Devuelta',
                        'rejected'       => 'Rechazada',
                        default          => $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'imported'       => 'Importada',
                        'partial_return' => 'Dev. Parcial',
                        'returned'       => 'Devuelta',
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
                    ->options(function (): array {
                        /** @var User $user */
                        $user = Auth::user();

                        $query = Invoice::where('manifest_id', $this->getOwnerRecord()->id);

                        if ($user->isWarehouseUser()) {
                            $query->where('warehouse_id', $user->warehouse_id);
                        }

                        return $query->distinct()
                            ->orderBy('route_number')
                            ->pluck('route_number', 'route_number')
                            ->toArray();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('imprimir_seleccionadas')
                        ->label('Imprimir Facturas')
                        ->icon('heroicon-o-printer')
                        ->color('info')
                        ->action(function (Collection $records): void {
                            /** @var \App\Models\Manifest $manifest */
                            $manifest   = $this->getOwnerRecord();
                            $invoiceIds = $records->pluck('id')->toArray();

                            $url = app(InvoicePdfService::class)
                                ->generatePrintUrl($manifest, $invoiceIds);

                            $this->js("window.open('{$url}', '_blank')");
                        })
                        ->deselectRecordsAfterCompletion(),

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
                            $this->js("window.open('/imprimir/reportes/productos?payload=" . urlencode($payload) . "', '_blank')");
                        })
                        ->deselectRecordsAfterCompletion(),

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
                            $this->js("window.open('/imprimir/reportes/facturas-checklist?payload=" . urlencode($payload) . "', '_blank')");
                        })
                        ->deselectRecordsAfterCompletion(),
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
                    $query->where('warehouse_id', $user->warehouse_id);
                }

                if (!empty($this->statusesFilter)) {
                    $query->whereIn('status', $this->statusesFilter);
                }
            })
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('Sin facturas')
            ->emptyStateDescription('No hay facturas asignadas a este manifiesto.')

            ->defaultSort('route_number', 'asc')
            ->striped()
            ->paginated([25, 50, 100, 200]);
    }
}