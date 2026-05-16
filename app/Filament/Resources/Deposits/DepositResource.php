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
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DepositResource extends Resource
{
    protected static ?string $model = Deposit::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Depósitos';

    protected static ?string $modelLabel = 'Depósito';

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
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'imported' => 'info',
                        'closed' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Pendiente',
                        'processing' => 'Procesando',
                        'imported' => 'Importado',
                        'closed' => 'Cerrado',
                        default => $state,
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

                IconColumn::make('cancelled_at')
                    ->label('Cancelado')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->tooltip(fn (Deposit $record): ?string => $record->isCancelled()
                        ? 'Cancelado por '.($record->cancelledBy?->name ?? '—').': '.$record->cancellation_reason
                        : null
                    ),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('deposit_date', 'desc')
            ->recordActions([
                ViewAction::make(),

                // Editar: solo en activos y manifests abiertos. Un cancelado
                // queda inmutable — su registro es histórico/auditable.
                EditAction::make()
                    ->hidden(fn (Deposit $record): bool => $record->isCancelled() || $record->manifest->isClosed()),

                // Cancelar: soft-cancel con razón. Visible para todos los
                // roles que tenían "Delete:Deposit" antes (admin, super_admin,
                // finance). El depósito queda en BD pero no cuenta en totales.
                Action::make('cancel')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn (Deposit $record): bool => ! $record->isCancelled()
                        && ! $record->manifest->isClosed()
                        && Auth::user()->can('delete', $record)
                    )
                    ->modalHeading('Cancelar depósito')
                    ->modalDescription('El depósito quedará anulado del total del manifiesto pero se conserva el registro para auditoría. Indicá el motivo.')
                    ->modalSubmitActionLabel('Cancelar depósito')
                    ->schema([
                        Textarea::make('cancellation_reason')
                            ->label('Motivo de la cancelación')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500)
                            ->rows(3)
                            ->placeholder('Ej. Depósito duplicado, monto incorrecto, error de captura...'),
                    ])
                    ->action(function (Deposit $record, array $data): void {
                        app(DepositService::class)
                            ->cancelDeposit($record, $data['cancellation_reason'], Auth::id());

                        Notification::make()
                            ->title('Depósito cancelado')
                            ->body("Se anuló del total del manifiesto #{$record->manifest->number}.")
                            ->warning()
                            ->send();
                    }),

                // Eliminar (hard delete): solo super_admin. Borra el registro
                // permanentemente — la opción normal es Cancelar.
                Action::make('forceDelete')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Deposit $record): bool => Auth::user()->hasRole('super_admin')
                        && ! $record->manifest->isClosed()
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Eliminar permanentemente')
                    ->modalDescription('Esta acción elimina el depósito definitivamente, sin posibilidad de recuperarlo. Si solo querés anularlo, usá "Cancelar" — preserva el registro.')
                    ->modalSubmitActionLabel('Eliminar definitivamente')
                    ->action(function (Deposit $record): void {
                        app(DepositService::class)->forceDeleteDeposit($record, Auth::id());

                        Notification::make()
                            ->title('Depósito eliminado permanentemente')
                            ->danger()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeposits::route('/'),
            'create' => CreateDeposit::route('/create'),
            'view' => ViewDeposit::route('/{record}'),
            'edit' => EditDeposit::route('/{record}/edit'),
        ];
    }
}
