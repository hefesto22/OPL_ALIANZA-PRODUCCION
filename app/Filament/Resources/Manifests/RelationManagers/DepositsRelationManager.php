<?php

namespace App\Filament\Resources\Manifests\RelationManagers;

use App\Models\Deposit;
use App\Services\DepositService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';

    protected static ?string $title = 'Depósitos';

    protected static ?string $label = 'Depósito';

    protected static ?string $pluralLabel = 'Depósitos';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        /** @var \App\Models\User $user */
        $user = \Illuminate\Support\Facades\Auth::user();

        return ! $user->hasRole('operador');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['createdBy:id,name']))
            ->columns([
                TextColumn::make('deposit_date')
                    ->label('Fecha de Depósito')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('HNL')
                    ->weight('bold')
                    ->color('success'),

                TextColumn::make('bank')
                    ->label('Banco')
                    ->placeholder('—'),

                TextColumn::make('reference')
                    ->label('Referencia / No. Boleta')
                    ->placeholder('—')
                    ->copyable()
                    ->copyMessage('Referencia copiada'),

                // Indicador visual de si tiene comprobante adjunto.
                IconColumn::make('receipt_image')
                    ->label('Comprobante')
                    ->boolean()
                    ->trueIcon('heroicon-o-photo')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (Deposit $record): string => $record->receipt_image
                        ? 'Tiene comprobante adjunto — clic en "Ver" para abrirlo'
                        : 'Sin comprobante adjunto'
                    ),

                TextColumn::make('observations')
                    ->label('Observaciones')
                    ->placeholder('—')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->observations)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('createdBy.name')
                    ->label('Registrado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // Marca visual de cancelado. Si el row está cancelado, el
                // tooltip muestra quién y por qué — el supervisor abriendo
                // el manifest puede distinguir y entender la causa sin
                // navegar al detalle del depósito.
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
            ])

            ->defaultSort('deposit_date', 'desc')
            ->striped()

            // ── Estado vacío ───────────────────────────────────────────
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading('Sin depósitos registrados')
            ->emptyStateDescription(fn (): string => $this->getOwnerRecord()->isClosed()
                ? 'Este manifiesto está cerrado.'
                : 'Usa el botón "Registrar Depósito" en la parte superior.'
            )

            ->recordActions([
                // ── Ver comprobante ────────────────────────────────────
                Action::make('view_receipt')
                    ->label('Ver')
                    ->icon('heroicon-o-photo')
                    ->color('info')
                    ->visible(fn (Deposit $record): bool => (bool) $record->receipt_image)
                    // Signed URL con TTL 30min — el accessor receipt_url
                    // del modelo lo genera. Usar el accessor centraliza
                    // el TTL en un solo lugar.
                    ->url(fn (Deposit $record): string => $record->receipt_url)
                    ->openUrlInNewTab(),

                // ── Editar depósito ────────────────────────────────────
                Action::make('edit_deposit')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (Deposit $record): bool => ! $record->isCancelled()
                        && ! $this->getOwnerRecord()->isClosed()
                    )
                    ->modalHeading('Editar Depósito')
                    ->modalSubmitActionLabel('Guardar Cambios')
                    ->fillForm(fn (Deposit $record): array => [
                        'amount' => $record->amount,
                        'deposit_date' => $record->deposit_date,
                        'bank' => $record->bank,
                        'reference' => $record->reference,
                        'observations' => $record->observations,
                        'receipt_image' => $record->receipt_image,
                    ])
                    ->schema(fn (Deposit $record): array => $this->getDepositFormSchema($record))
                    ->action(function (Deposit $record, array $data): void {
                        app(DepositService::class)->updateDeposit($record, $data, Auth::id());
                    }),

                // ── Cancelar depósito ──────────────────────────────────
                // Reemplaza al "Eliminar" anterior. El depósito queda en BD
                // (auditoría) pero no cuenta en los totales del manifiesto.
                // Requiere razón documentada — eso permite reconstruir
                // después qué pasó y por qué.
                Action::make('cancel_deposit')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn (Deposit $record): bool => ! $record->isCancelled()
                        && ! $this->getOwnerRecord()->isClosed()
                        && Auth::user()->can('delete', $record)
                    )
                    ->modalHeading('Cancelar depósito')
                    ->modalDescription('El depósito quedará anulado del total del manifiesto pero se conserva el registro para auditoría. Indicá el motivo.')
                    ->modalSubmitActionLabel('Cancelar depósito')
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('cancellation_reason')
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
                    }),

                // ── Eliminar permanentemente (solo super_admin) ────────
                // Hard delete: borra el registro y el archivo del comprobante.
                // La opción normal para anular es Cancelar.
                Action::make('force_delete_deposit')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Deposit $record): bool => Auth::user()->hasRole('super_admin')
                        && ! $this->getOwnerRecord()->isClosed()
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Eliminar permanentemente')
                    ->modalDescription('Esta acción elimina el depósito definitivamente, sin posibilidad de recuperarlo. Si solo querés anularlo, usá "Cancelar" — preserva el registro.')
                    ->modalSubmitActionLabel('Eliminar definitivamente')
                    ->action(function (Deposit $record): void {
                        app(DepositService::class)->forceDeleteDeposit($record, Auth::id());
                    }),
            ]);
    }

    /**
     * Schema del formulario de depósito.
     *
     * En modo edición se pasa el $record para que el maxValue incluya
     * el monto del depósito actual (que "se devuelve al pool" al editar).
     * En modo creación $record = null y se usa el saldo pendiente normal.
     */
    private function getDepositFormSchema(?Deposit $record = null): array
    {
        $depositService = app(DepositService::class);
        $manifest = $this->getOwnerRecord();

        // Al editar, el saldo disponible = pendiente actual + monto del depósito que se está editando.
        // Esto evita que el usuario no pueda cambiar el monto aunque no lo esté aumentando.
        $pending = $depositService->getPendingAmount($manifest);
        if ($record !== null) {
            $pending = $pending + (float) $record->amount;
        }

        return [
            TextInput::make('amount')
                ->label('Monto')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->maxValue($pending + 0.01)
                ->prefix('HNL')
                ->placeholder('0.00')
                ->helperText('Saldo disponible: HNL '.number_format($pending, 2)),

            DatePicker::make('deposit_date')
                ->label('Fecha de Depósito')
                ->required()
                ->default(today())
                ->maxDate(today()),

            TextInput::make('bank')
                ->label('Banco')
                ->maxLength(100)
                ->placeholder('Ej. Banco Atlántida'),

            TextInput::make('reference')
                ->label('Referencia / No. Boleta')
                ->maxLength(100)
                ->placeholder('Número de referencia del depósito'),

            Textarea::make('observations')
                ->label('Observaciones')
                ->rows(3)
                ->placeholder('Notas adicionales sobre el depósito...'),

            // ── Comprobante de depósito ────────────────────────────────
            // Conversión a WebP automática vía ReceiptImageService:
            // resize a 1400×1400 max + WebP calidad 85, sin importar el
            // formato de entrada. A escala (~100 depósitos/día con foto)
            // ahorra ~60% de disco vs guardar el JPG original.
            //
            // El imageEditor sigue funcionando: el usuario puede recortar/
            // rotar en el browser ANTES de que el upload llegue al server;
            // el resultado final del editor entra al pipeline de conversión.
            //
            // saveUploadedFileUsing reemplaza los automaticallyResize* —
            // el Service hace tanto el resize como la conversión.
            FileUpload::make('receipt_image')
                ->label('Comprobante (foto/imagen)')
                ->helperText('Sube la foto del recibo o boleta bancaria. Cualquier formato (JPG, PNG, WEBP) se convierte automáticamente a WebP optimizado para ocupar menos espacio. Máx. 8 MB.')
                ->image()
                ->imageEditor()
                ->disk('local')
                ->directory('deposits/receipts')
                ->visibility('private')
                ->maxSize(8192)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->saveUploadedFileUsing(
                    fn ($file): string => app(\App\Services\ReceiptImageService::class)
                        ->convertToWebp($file)
                )
                ->nullable()
                ->columnSpanFull(),
        ];
    }
}
