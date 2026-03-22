<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\Manifest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestManifestsWidget extends BaseWidget
{
    protected static ?int $sort = 7;
    protected int | string | array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'Últimos Manifiestos';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // Reutilizamos el query del resource para heredar el scope
                // de bodega automáticamente (admin ve todo, encargado ve su bodega).
                ManifestResource::getEloquentQuery()
                    ->latest('date')
                    ->limit(8)
            )
            ->columns([
                TextColumn::make('number')
                    ->label('# Manifiesto')
                    ->weight('bold')
                    ->url(fn (Manifest $record): string => ManifestResource::getUrl('view', ['record' => $record]))
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('invoices_count')
                    ->label('Facturas')
                    ->numeric()
                    ->alignCenter(),

                TextColumn::make('total_invoices')
                    ->label('Total Facturado')
                    ->money('HNL')
                    ->sortable(),

                TextColumn::make('total_returns')
                    ->label('Devoluciones')
                    ->money('HNL')
                    ->color(fn ($state): string => (float)$state > 0 ? 'danger' : 'gray'),

                TextColumn::make('total_to_deposit')
                    ->label('A Depositar')
                    ->money('HNL')
                    ->color('warning')
                    ->weight('bold'),

                TextColumn::make('total_deposited')
                    ->label('Depositado')
                    ->money('HNL')
                    ->color('success'),

                TextColumn::make('difference')
                    ->label('Diferencia')
                    ->money('HNL')
                    ->color(fn ($record): string => (float)$record->difference === 0.0 ? 'success' : 'danger')
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'    => 'gray',
                        'processing' => 'info',
                        'imported'   => 'warning',
                        'closed'     => 'success',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'    => 'Pendiente',
                        'processing' => 'En Proceso',
                        'imported'   => 'Importado',
                        'closed'     => 'Cerrado',
                        default      => $state,
                    }),
            ])
            ->paginated(false)
            ->striped();
    }
}
