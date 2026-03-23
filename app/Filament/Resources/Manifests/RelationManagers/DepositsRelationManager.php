<?php

namespace App\Filament\Resources\Manifests\RelationManagers;

use App\Models\Deposit;
use App\Services\DepositService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';
    protected static ?string $title       = 'Depósitos';
    protected static ?string $label       = 'Depósito';
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
                    ->url(fn (Deposit $record): string => route('deposits.receipt', $record))
                    ->openUrlInNewTab(),

                // ── Editar depósito ────────────────────────────────────
                Action::make('edit_deposit')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->hidden(fn (): bool => $this->getOwnerRecord()->isClosed())
                    ->modalHeading('Editar Depósito')
                    ->modalSubmitActionLabel('Guardar Cambios')
                    ->fillForm(fn (Deposit $record): array => [
                        'amount'        => $record->amount,
                        'deposit_date'  => $record->deposit_date,
                        'bank'          => $record->bank,
                        'reference'     => $record->reference,
                        'observations'  => $record->observations,
                        'receipt_image' => $record->receipt_image,
                    ])
                    ->schema(fn (Deposit $record): array => $this->getDepositFormSchema($record))
                    ->action(function (Deposit $record, array $data): void {
                        app(DepositService::class)->updateDeposit($record, $data, Auth::id());
                    }),

                // ── Eliminar depósito ──────────────────────────────────
                Action::make('delete_deposit')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->hidden(fn (): bool => $this->getOwnerRecord()->isClosed())
                    ->requiresConfirmation()
                    ->modalHeading('¿Eliminar este depósito?')
                    ->modalDescription('Esta acción no se puede deshacer. El comprobante adjunto también se eliminará.')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->action(function (Deposit $record): void {
                        app(DepositService::class)->deleteDeposit($record);
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
        $manifest       = $this->getOwnerRecord();

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
                ->helperText('Saldo disponible: HNL ' . number_format($pending, 2)),

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
            FileUpload::make('receipt_image')
                ->label('Comprobante (foto/imagen)')
                ->helperText('Sube la foto del recibo o boleta bancaria. Formatos: JPG, PNG, WEBP. Máx. 8 MB. El archivo se guarda de forma privada y se elimina automáticamente después de 2 meses.')
                ->image()
                ->imageEditor()          // permite recortar/rotar antes de guardar
                ->imageResizeMode('contain')
                ->imageResizeTargetWidth('1400')   // máx 1400px — calidad suficiente, peso reducido
                ->imageResizeTargetHeight('1400')
                ->disk('local')          // disco PRIVADO — no accesible vía URL directa
                ->directory('deposits/receipts')
                ->visibility('private')
                ->maxSize(8192)          // 8 MB para fotos de celular sin comprimir
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->nullable()
                ->columnSpanFull(),
        ];
    }
}
