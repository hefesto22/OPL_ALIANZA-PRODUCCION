<?php

namespace App\Filament\Resources\Returns\Tables;

use App\Models\InvoiceReturn;
use App\Models\User;
use App\Services\ReturnService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ReturnsTable
{
    public static function make(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('# Dev.')
                    ->sortable(),

                TextColumn::make('invoice.invoice_number')
                    ->label('# Factura')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('manifest.number')
                    ->label('# Manifiesto')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->sortable()
                    ->visible(function () {
                        /** @var User $user */
                        $user = Auth::user();

                        return $user->isGlobalUser();
                    }),

                TextColumn::make('client_name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('returnReason.code')
                    ->label('Motivo')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'total' => 'Total',
                        'partial' => 'Parcial',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'total' => 'danger',
                        'partial' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'Pendiente',
                        'approved' => 'Aprobada',
                        'rejected' => 'Rechazada',
                        'cancelled' => 'Cancelada',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable(),

                TextColumn::make('return_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('processed_date')
                    ->label('Fecha Procesado')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('createdBy.name')
                    ->label('Registrado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pending' => 'Pendiente',
                        'approved' => 'Aprobada',
                        'rejected' => 'Rechazada',
                        'cancelled' => 'Cancelada',
                    ]),

                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(['total' => 'Total', 'partial' => 'Parcial']),

                SelectFilter::make('return_reason_id')
                    ->label('Motivo')
                    ->relationship('returnReason', 'description'),

                SelectFilter::make('warehouse_id')
                    ->label('Bodega')
                    ->relationship('warehouse', 'code')
                    ->visible(function () {
                        /** @var User $user */
                        $user = Auth::user();

                        return $user->isGlobalUser();
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                EditAction::make()
                    ->hidden(fn (InvoiceReturn $record): bool => $record->isCancelled() ||
                        $record->manifest->isClosed() ||
                        ! $record->isEditableToday()
                    ),

                Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar devolución')
                    ->modalDescription('¿Estás seguro de cancelar esta devolución? Los totales del manifiesto y el estado de la factura se recalcularán.')
                    ->schema([
                        Textarea::make('cancellation_reason')
                            ->label('Motivo de cancelación')
                            ->placeholder('Ej: Error en cantidad, producto equivocado, duplicada...')
                            ->required()
                            ->maxLength(500)
                            ->rows(3),
                    ])
                    ->action(function (InvoiceReturn $record, array $data): void {
                        app(ReturnService::class)->cancelReturn($record, $data['cancellation_reason']);

                        Notification::make()
                            ->title('Devolución cancelada')
                            ->body('Los totales del manifiesto y el estado de la factura fueron recalculados.')
                            ->success()
                            ->send();
                    })
                    ->hidden(fn (InvoiceReturn $record): bool => $record->isCancelled() ||
                        $record->manifest->isClosed()
                    ),
            ])
            ->defaultSort('id', 'desc')
            ->striped();
    }
}
