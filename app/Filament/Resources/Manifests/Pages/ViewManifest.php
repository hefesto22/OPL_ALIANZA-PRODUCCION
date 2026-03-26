<?php

namespace App\Filament\Resources\Manifests\Pages;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\Deposit;
use App\Models\User;
use App\Services\DepositService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class ViewManifest extends ViewRecord
{
    protected static string $resource = ManifestResource::class;

    /**
     * Título dinámico que muestra el número del manifiesto.
     * El usuario sabe de un vistazo qué registro está viendo
     * sin tener que buscar el número en el contenido.
     */
    public function getTitle(): string
    {
        return "Manifiesto #{$this->record->number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            // ── Editar (solo super_admin, oculto si cerrado) ────────────
            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->hidden(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    return $this->record->isClosed() || !$user->hasRole('super_admin');
                }),

            // ── Cerrar manifiesto (solo super_admin y admin) ────────────
            Action::make('close')
                ->label('Cerrar Manifiesto')
                ->icon('heroicon-o-lock-closed')
                ->color('success')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-lock-closed')
                ->modalHeading('¿Cerrar este manifiesto?')
                ->modalDescription('Una vez cerrado no podrá modificarse. Solo un administrador podrá reabrirlo.')
                ->modalSubmitActionLabel('Sí, cerrar')
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    return $this->record->isReadyToClose() && $user->hasAnyRole(['super_admin', 'admin']);
                })
                ->action(function (): void {
                    $this->record->close(Auth::id());

                    Notification::make()
                        ->title("Manifiesto #{$this->record->number} cerrado correctamente.")
                        ->body('El manifiesto ha sido cerrado y ya no puede ser modificado.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'closed_at', 'closed_by']);
                }),

            // ── Reabrir manifiesto (solo super_admin) ──────────────────
            Action::make('reopen')
                ->label('Reabrir Manifiesto')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-lock-open')
                ->modalHeading('¿Reabrir este manifiesto?')
                ->modalDescription('El manifiesto volverá al estado "Importado" y podrá ser modificado nuevamente.')
                ->modalSubmitActionLabel('Sí, reabrir')
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    return $this->record->isClosed() && $user->hasRole('super_admin');
                })
                ->action(function (): void {
                    $this->record->reopen();

                    Notification::make()
                        ->title("Manifiesto #{$this->record->number} reabierto.")
                        ->body('El manifiesto volvió al estado "Importado" y puede ser modificado.')
                        ->warning()
                        ->send();

                    $this->refreshFormData(['status', 'closed_at', 'closed_by']);
                }),

            // ── Registrar Depósito (super_admin, admin, encargado, finance) ──
            Action::make('registrar_deposito')
                ->label('Registrar Depósito')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->button()
                ->hidden(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    $noPermission = !$user->hasAnyRole(['super_admin', 'admin', 'encargado', 'finance']);
                    return $this->record->isClosed() || (float) $this->record->difference === 0.0 || $noPermission;
                })
                ->modalHeading('Registrar Depósito')
                ->modalDescription(function (): string {
                    $pending = app(DepositService::class)->getPendingAmount($this->record);
                    return 'Saldo pendiente de depositar: HNL ' . number_format($pending, 2);
                })
                ->modalSubmitActionLabel('Guardar Depósito')
                ->modalWidth('lg')
                ->schema(function (): array {
                    $pending = app(DepositService::class)->getPendingAmount($this->record);
                    return [
                        TextInput::make('amount')
                            ->label('Monto')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('HNL')
                            ->placeholder('0.00')
                            ->helperText('Saldo pendiente: HNL ' . number_format($pending, 2)),

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
                            ->placeholder('Número de referencia'),

                        Textarea::make('observations')
                            ->label('Observaciones')
                            ->rows(2)
                            ->placeholder('Notas adicionales...'),

                        FileUpload::make('receipt_image')
                            ->label('Comprobante (foto/imagen)')
                            ->helperText('Opcional. JPG, PNG, WEBP. Máx. 8 MB.')
                            ->image()
                            ->imageEditor()
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1400')
                            ->imageResizeTargetHeight('1400')
                            ->disk('local')
                            ->directory('deposits/receipts')
                            ->visibility('private')
                            ->maxSize(8192)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->nullable()
                            ->columnSpanFull(),
                    ];
                })
                ->action(function (array $data): void {
                    $deposit = app(DepositService::class)->createDeposit(
                        $this->record,
                        $data,
                        Auth::id()
                    );

                    // Recargar el record en memoria para que el infolist refleje
                    // los nuevos totales (total_deposited, difference, etc.)
                    $this->record->refresh();

                    Notification::make()
                        ->title('Depósito registrado correctamente')
                        ->body('HNL ' . number_format($deposit->amount, 2) . ' — ' . ($deposit->bank ?? 'Sin banco'))
                        ->success()
                        ->send();
                }),

            // ── Reportes de facturas (super_admin, admin, encargado) ───
            ActionGroup::make([
                Action::make('report_facturas_pdf')
                    ->label('Reporte PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('danger')
                    ->visible(fn (): bool => Auth::user()->hasAnyRole(['super_admin', 'admin', 'encargado', 'finance']))
                    ->action(function (): void {
                        /** @var User $user */
                        $user = Auth::user();

                        $payloadData = [
                            'manifest_id' => $this->record->id,
                        ];

                        // Si el usuario tiene bodega asignada, filtrar solo sus facturas
                        if ($user->warehouse_id) {
                            $payloadData['warehouse_id'] = $user->warehouse_id;
                        }

                        $payload = Crypt::encryptString(json_encode($payloadData));

                        $this->js("window.open('/imprimir/reportes/facturas?payload=" . urlencode($payload) . "', '_blank')");
                    }),

                Action::make('report_productos_pdf')
                    ->label('Sublista Productos')
                    ->icon('heroicon-o-cube')
                    ->color('warning')
                    ->action(function (): void {
                        /** @var User $user */
                        $user = Auth::user();

                        $payloadData = [
                            'manifest_id' => $this->record->id,
                        ];

                        if ($user->warehouse_id) {
                            $payloadData['warehouse_id'] = $user->warehouse_id;
                        }

                        $payload = Crypt::encryptString(json_encode($payloadData));

                        $this->js("window.open('/imprimir/reportes/productos?payload=" . urlencode($payload) . "', '_blank')");
                    }),

                Action::make('report_facturas_checklist')
                    ->label('Sublista Facturas')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('success')
                    ->action(function (): void {
                        /** @var User $user */
                        $user = Auth::user();

                        $payloadData = [
                            'manifest_id' => $this->record->id,
                        ];

                        if ($user->warehouse_id) {
                            $payloadData['warehouse_id'] = $user->warehouse_id;
                        }

                        $payload = Crypt::encryptString(json_encode($payloadData));

                        $this->js("window.open('/imprimir/reportes/facturas-checklist?payload=" . urlencode($payload) . "', '_blank')");
                    }),

            ])
                ->label('Facturas')
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray')
                ->button()
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    return $user->hasAnyRole(['super_admin', 'admin', 'encargado', 'operador']);
                }),

            // ── Reporte PDF de devoluciones (super_admin, admin, encargado) ──
            // Jaremar consume los datos vía API; Hozana genera el PDF.
            // El modal permite filtrar por período antes de imprimir.
            Action::make('report_devoluciones_pdf')
                ->label('Devoluciones')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->button()
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    return $this->record->returns()->exists() && $user->hasAnyRole(['super_admin', 'admin', 'encargado']);
                })
                ->modalHeading('Reporte de Devoluciones')
                ->modalDescription('Selecciona el período que deseas incluir en el reporte.')
                ->modalIcon('heroicon-o-document-chart-bar')
                ->modalSubmitActionLabel('Generar PDF')
                ->modalWidth('md')
                ->schema([
                    Select::make('period')
                        ->label('Período')
                        ->options([
                            'all'    => 'Todas las devoluciones',
                            'today'  => 'Hoy',
                            'week'   => 'Esta semana',
                            'month'  => 'Este mes',
                            'custom' => 'Rango personalizado',
                        ])
                        ->default('all')
                        ->required()
                        ->live(),

                    DatePicker::make('date_from')
                        ->label('Desde')
                        ->displayFormat('d/m/Y')
                        ->maxDate(now())
                        ->required()
                        ->visible(fn ($get) => $get('period') === 'custom'),

                    DatePicker::make('date_to')
                        ->label('Hasta')
                        ->displayFormat('d/m/Y')
                        ->maxDate(now())
                        ->required()
                        ->visible(fn ($get) => $get('period') === 'custom'),
                ])
                ->action(function (array $data): void {
                    [$from, $to] = match ($data['period']) {
                        'today'  => [now()->startOfDay()->format('Y-m-d'), now()->endOfDay()->format('Y-m-d')],
                        'week'   => [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')],
                        'month'  => [now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d')],
                        'custom' => [$data['date_from'], $data['date_to']],
                        default  => [null, null],
                    };

                    $payload = Crypt::encryptString(json_encode([
                        'manifest_id' => $this->record->id,
                        'date_from'   => $from,
                        'date_to'     => $to,
                        'period'      => $data['period'],
                    ]));

                    $this->js("window.open('/imprimir/reportes/devoluciones?payload=" . urlencode($payload) . "', '_blank')");
                }),
        ];
    }
}
