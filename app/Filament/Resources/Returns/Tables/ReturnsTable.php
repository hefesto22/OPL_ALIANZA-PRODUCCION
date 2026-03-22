<?php

namespace App\Filament\Resources\Returns\Tables;

use App\Models\InvoiceReturn;
use App\Models\User;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
                    ->formatStateUsing(fn($state) => match($state) {
                        'total'   => 'Total',
                        'partial' => 'Parcial',
                        default   => $state,
                    })
                    ->color(fn($state) => match($state) {
                        'total'   => 'danger',
                        'partial' => 'warning',
                        default   => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => match($state) {
                        'pending'  => 'Pendiente',
                        'approved' => 'Aprobada',
                        'rejected' => 'Rechazada',
                        default    => $state,
                    })
                    ->color(fn($state) => match($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'gray',
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
                        'pending'  => 'Pendiente',
                        'approved' => 'Aprobada',
                        'rejected' => 'Rechazada',
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

                EditAction::make(),

                DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->striped();
    }
}