<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Filament\Resources\Manifests\ManifestResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class ViewDeposit extends ViewRecord
{
    protected static string $resource = DepositResource::class;

    protected static ?string $title = 'Ver Depósito';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->hidden(fn() => $this->record->manifest->isClosed()),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([

            Section::make('Datos del Depósito')
                ->icon('heroicon-o-banknotes')
                ->columns(4)
                ->schema([
                    TextEntry::make('manifest.number')
                        ->label('# Manifiesto')
                        ->weight(FontWeight::Bold)
                        ->url(fn() => ManifestResource::getUrl('view', ['record' => $this->record->manifest_id]))
                        ->color('primary'),

                    TextEntry::make('manifest.status')
                        ->label('Estado Manifiesto')
                        ->badge()
                        ->color(fn(string $state) => match($state) {
                            'pending'    => 'gray',
                            'processing' => 'warning',
                            'imported'   => 'info',
                            'closed'     => 'success',
                            default      => 'gray',
                        })
                        ->formatStateUsing(fn(string $state) => match($state) {
                            'pending'    => 'Pendiente',
                            'processing' => 'Procesando',
                            'imported'   => 'Importado',
                            'closed'     => 'Cerrado',
                            default      => $state,
                        }),

                    TextEntry::make('deposit_date')
                        ->label('Fecha de Depósito')
                        ->date('d/m/Y')
                        ->icon('heroicon-m-calendar-days'),

                    TextEntry::make('amount')
                        ->label('Monto')
                        ->money('HNL')
                        ->weight(FontWeight::Bold)
                        ->color('success'),

                    TextEntry::make('bank')
                        ->label('Banco')
                        ->placeholder('—'),

                    TextEntry::make('reference')
                        ->label('Referencia / No. Boleta')
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->copyable(),

                    TextEntry::make('observations')
                        ->label('Observaciones')
                        ->placeholder('—')
                        ->columnSpan(2),
                ]),

            Section::make('Auditoría')
                ->icon('heroicon-o-shield-check')
                ->columns(2)
                ->schema([
                    TextEntry::make('createdBy.name')
                        ->label('Registrado por')
                        ->icon('heroicon-m-user'),

                    TextEntry::make('created_at')
                        ->label('Fecha de Registro')
                        ->dateTime('d/m/Y H:i')
                        ->icon('heroicon-m-calendar'),
                ]),
        ]);
    }
}