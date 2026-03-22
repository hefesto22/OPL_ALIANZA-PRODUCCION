<?php

namespace App\Filament\Resources\Deposits;

use App\Filament\Resources\Deposits\Pages\CreateDeposit;
use App\Filament\Resources\Deposits\Pages\EditDeposit;
use App\Filament\Resources\Deposits\Pages\ListDeposits;
use App\Filament\Resources\Deposits\Pages\ViewDeposit;
use App\Filament\Resources\Deposits\Schemas\DepositForm;
use App\Models\Deposit;
use App\Services\DepositService;
use App\Support\WarehouseScope;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DepositResource extends Resource
{
    protected static ?string $model = Deposit::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel  = 'Depósitos';
    protected static ?string $modelLabel       = 'Depósito';
    protected static ?string $pluralModelLabel = 'Depósitos';

    /**
     * Los depósitos no tienen warehouse_id directo; se filtran a través
     * del manifiesto al que pertenecen.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['manifest', 'createdBy']);

        return WarehouseScope::applyViaRelation($query, 'manifest');
    }

    public static function form(Schema $schema): Schema
    {
        return DepositForm::make($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('manifest.number')
                    ->label('# Manifiesto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('manifest.status')
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

                TextColumn::make('deposit_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                TextColumn::make('bank')
                    ->label('Banco')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('reference')
                    ->label('Referencia')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('createdBy.name')
                    ->label('Registrado por')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('deposit_date', 'desc')
            ->recordActions([
                ViewAction::make(),

                EditAction::make()
                    ->hidden(fn(Deposit $record) => $record->manifest->isClosed()),

                DeleteAction::make()
                    ->hidden(fn(Deposit $record) => $record->manifest->isClosed())
                    ->using(fn(Deposit $record) => app(DepositService::class)->deleteDeposit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('delete')
                        ->label('Eliminar seleccionados')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $service = app(DepositService::class);
                            $blocked = 0;
                            $deleted = 0;

                            foreach ($records as $record) {
                                if ($record->manifest->isClosed()) {
                                    $blocked++;
                                    continue;
                                }
                                $service->deleteDeposit($record);
                                $deleted++;
                            }

                            if ($deleted > 0) {
                                Notification::make()
                                    ->title("{$deleted} depósito(s) eliminado(s).")
                                    ->success()
                                    ->send();
                            }

                            if ($blocked > 0) {
                                Notification::make()
                                    ->title("{$blocked} depósito(s) no eliminado(s)")
                                    ->body('Pertenecen a manifiestos cerrados.')
                                    ->warning()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListDeposits::route('/'),
            'create' => CreateDeposit::route('/create'),
            'view'   => ViewDeposit::route('/{record}'),
            'edit'   => EditDeposit::route('/{record}/edit'),
        ];
    }
}