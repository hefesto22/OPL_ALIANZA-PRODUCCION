<?php

namespace App\Filament\Resources\Manifests\RelationManagers;

use App\Models\InvoiceReturn;
use App\Models\User;
use App\Services\ReturnService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    protected static ?string $title = 'Devoluciones';

    protected static ?string $label = 'Devolución';

    protected static ?string $pluralLabel = 'Devoluciones';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        /** @var User $user */
        $user = Auth::user();

        return ! $user->hasRole('operador');
    }

    public function table(Table $table): Table
    {
        $isClosed = $this->getOwnerRecord()->isClosed();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'invoice:id,invoice_number',
                'warehouse:id,code',
                'returnReason:id,code,description',
                'reviewedBy:id,name',
            ]))
            ->columns([
                // Prefijo DEV- hace el ID más legible y evita confundirlo
                // con el número de factura u otros IDs del sistema.
                TextColumn::make('id')
                    ->label('# Dev.')
                    ->formatStateUsing(fn ($state): string => "DEV-{$state}")
                    ->sortable(),

                TextColumn::make('invoice.invoice_number')
                    ->label('# Factura')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Número copiado'),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'OAC' => 'info',
                        'OAO' => 'success',
                        'OAS' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('client_name')
                    ->label('Cliente')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->client_name)
                    ->searchable(),

                // El tooltip muestra la descripción completa del motivo.
                TextColumn::make('returnReason.code')
                    ->label('Motivo')
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn ($record) => $record->returnReason?->description),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'total' => 'Total',
                        'partial' => 'Parcial',
                        default => $state,
                    })
                    ->color(fn ($state): string => match ($state) {
                        'total' => 'danger',
                        'partial' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->icon(fn ($state): string => match ($state) {
                        'pending' => 'heroicon-o-clock',
                        'approved' => 'heroicon-o-check-circle',
                        'rejected' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'pending' => 'Pendiente',
                        'approved' => 'Aprobada',
                        'rejected' => 'Rechazada',
                        default => $state,
                    })
                    ->color(fn ($state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('return_date')
                    ->label('Fecha Dev.')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('reviewedBy.name')
                    ->label('Revisado por')
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
                    ]),

                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'total' => 'Total',
                        'partial' => 'Parcial',
                    ]),
            ])

            ->recordActions([
                // Editar — solo el mismo día calendario del registro.
                // Después de medianoche Jaremar puede haber consumido la
                // devolución vía API, por lo que se bloquea para evitar
                // inconsistencias. El tooltip explica el motivo al usuario.
                Action::make('edit')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->url(fn (InvoiceReturn $record): string => \App\Filament\Resources\Returns\ReturnResource::getUrl('edit', ['record' => $record])
                    )
                    ->visible(function (InvoiceReturn $record) use ($isClosed): bool {
                        return ! $isClosed && $record->isEditableToday();
                    })
                    ->tooltip(fn (InvoiceReturn $record): string => $record->getEditabilityLabel()
                    ),

                // Icono de candado — visible al día siguiente para que el usuario
                // entienda por qué no puede editar (en lugar de simplemente ocultar).
                Action::make('locked')
                    ->label('Bloqueada')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->disabled()
                    ->tooltip(fn (InvoiceReturn $record): string => $record->getEditabilityLabel()
                    )
                    ->visible(function (InvoiceReturn $record) use ($isClosed): bool {
                        return ! $isClosed && ! $record->isEditableToday();
                    }),

                // Aprobar — solo rol haremar, pendientes, manifiesto abierto
                Action::make('approve')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(function (InvoiceReturn $record) use ($isClosed): bool {
                        /** @var User $user */
                        $user = Auth::user();

                        return $record->isPending()
                            && ! $isClosed
                            && $user->hasRole('haremar');
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalHeading('¿Aprobar esta devolución?')
                    ->modalDescription('La devolución será aprobada y se descontará del total a depositar del manifiesto.')
                    ->modalSubmitActionLabel('Sí, aprobar')
                    ->action(function (InvoiceReturn $record): void {
                        app(ReturnService::class)->approveReturn($record, Auth::id());

                        Notification::make()
                            ->title('Devolución aprobada.')
                            ->body("DEV-{$record->id} — {$record->client_name}")
                            ->success()
                            ->send();
                    }),

                // Rechazar — solo rol haremar, pendientes, manifiesto abierto
                Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(function (InvoiceReturn $record) use ($isClosed): bool {
                        /** @var User $user */
                        $user = Auth::user();

                        return $record->isPending()
                            && ! $isClosed
                            && $user->hasRole('haremar');
                    })
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Motivo del rechazo')
                            ->required()
                            ->rows(3)
                            ->placeholder('Explica por qué se rechaza esta devolución...'),
                    ])
                    ->modalHeading('Rechazar devolución')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalSubmitActionLabel('Confirmar rechazo')
                    ->action(function (InvoiceReturn $record, array $data): void {
                        app(ReturnService::class)->rejectReturn(
                            $record,
                            Auth::id(),
                            $data['rejection_reason']
                        );

                        Notification::make()
                            ->title('Devolución rechazada.')
                            ->body("DEV-{$record->id} — {$record->client_name}")
                            ->warning()
                            ->send();
                    }),

                // Ver detalle completo en el módulo de Devoluciones
                ViewAction::make()
                    ->label('Ver detalle')
                    ->url(fn (InvoiceReturn $record): string => \App\Filament\Resources\Returns\ReturnResource::getUrl('view', ['record' => $record])
                    ),
            ])

            ->emptyStateIcon('heroicon-o-arrow-uturn-left')
            ->emptyStateHeading('Sin devoluciones')
            ->emptyStateDescription('No se han registrado devoluciones en este manifiesto.')

            ->defaultSort('id', 'desc')
            ->striped();
    }
}
