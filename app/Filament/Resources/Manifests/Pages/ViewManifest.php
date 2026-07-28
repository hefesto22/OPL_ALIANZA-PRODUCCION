<?php

namespace App\Filament\Resources\Manifests\Pages;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\User;
use App\Services\DepositService;
use App\Services\ManifestAdjustmentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;

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

                    return $this->record->isClosed() || ! $user->hasRole('super_admin');
                }),

            // ── Cerrar manifiesto (solo super_admin y admin) ────────────
            Action::make('close')
                ->label('Cerrar Manifiesto')
                ->icon('heroicon-o-lock-closed')
                ->color('success')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-lock-closed')
                ->modalHeading('¿Cerrar este manifiesto?')
                ->modalDescription(function (): string {
                    $base = 'Una vez cerrado no podrá modificarse. Solo un administrador podrá reabrirlo.';

                    // Cerrar con plata de más no es un cierre de rutina: se
                    // avisa explícitamente para que nadie lo haga sin verlo.
                    if ($this->record->isOverpaid()) {
                        return 'Este manifiesto tiene un SOBREPAGO de HNL '.
                            number_format($this->record->overpaidAmount(), 2).
                            '. Se cerrará dejando constancia del exceso y de la justificación del depósito. '.
                            $base;
                    }

                    return $base;
                })
                ->modalSubmitActionLabel('Sí, cerrar')
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();

                    return $this->record->isReadyToClose() && $user->can('close', $this->record);
                })
                ->action(function (): void {
                    app(\App\Services\ManifestService::class)
                        ->closeManifest($this->record, Auth::id());

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

                    return $this->record->isClosed() && $user->can('reopen', $this->record);
                })
                ->action(function (): void {
                    app(\App\Services\ManifestService::class)
                        ->reopenManifest($this->record);

                    Notification::make()
                        ->title("Manifiesto #{$this->record->number} reabierto.")
                        ->body('El manifiesto volvió al estado "Importado" y puede ser modificado.')
                        ->warning()
                        ->send();

                    $this->refreshFormData(['status', 'closed_at', 'closed_by']);
                }),

            // ── Registrar Depósito ──────────────────────────────────────
            // Autorización vía DepositPolicy::create (permiso Create:Deposit,
            // administrable desde Shield → Recursos → Deposit). Antes era un
            // hasAnyRole inline que duplicaba —y podía contradecir— la matriz.
            Action::make('registrar_deposito')
                ->label('Registrar Depósito')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->button()
                ->hidden(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    $noPermission = ! $user->can('create', \App\Models\Deposit::class);

                    // Se oculta con diferencia <= 0: en cero no hay nada que
                    // depositar, y en negativo el manifiesto ya está
                    // sobre-depositado (agregar más lo empeoraría — eso se
                    // resuelve con "Ajustar Diferencia" o revirtiendo la boleta).
                    return $this->record->isClosed()
                        || (float) $this->record->difference <= 0.0
                        || $noPermission;
                })
                ->modalHeading('Registrar Depósito')
                ->modalDescription(function (): string {
                    $pending = app(DepositService::class)->getPendingAmount($this->record);

                    return 'Saldo pendiente de depositar: HNL '.number_format($pending, 2);
                })
                ->modalSubmitActionLabel('Guardar Depósito')
                ->modalWidth('lg')
                ->schema(function (): array {
                    $service = app(DepositService::class);
                    $record = $this->record;
                    $pending = $service->getPendingAmount($record);

                    // El monto ya NO tiene tope: una sola boleta puede cubrir
                    // varios manifiestos. Este closure decide cuándo mostrar el
                    // desglose del reparto y exigir justificación.
                    $exceedsPending = fn (Get $get): bool => round((float) ($get('amount') ?? 0), 2) > round($pending, 2);

                    return [
                        TextInput::make('amount')
                            ->label('Monto')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('HNL')
                            ->placeholder('0.00')
                            // live(onBlur): el desglose se recalcula al salir del
                            // campo, no en cada tecla. Cada recálculo consulta los
                            // saldos de los manifiestos candidatos — dispararlo por
                            // pulsación sería una query por dígito tecleado.
                            ->live(onBlur: true)
                            ->helperText(function () use ($pending): string {
                                $allocation = app(\App\Services\DepositAllocationService::class);

                                return 'Saldo pendiente: HNL '.number_format($pending, 2).
                                    '. Podés registrar un monto mayor. El excedente cubre primero deudas '.
                                    'chicas y viejas de la misma bodega (hasta HNL '.
                                    number_format($allocation->topePendiente(), 2).
                                    ' en manifiestos de más de '.$allocation->antiguedadMinima().
                                    ' días); lo que quede se registra en este manifiesto como sobrepago.';
                            }),

                        // ── Desglose del reparto ──────────────────────────
                        // Solo aparece cuando el monto supera el pendiente.
                        // Es una previsualización: el reparto definitivo se
                        // recalcula al guardar, con los manifiestos bloqueados
                        // y saldos frescos (otro usuario pudo depositar entre
                        // medias). Por eso se advierte que es estimado.
                        Placeholder::make('reparto_preview')
                            ->label('Así se va a repartir el depósito')
                            ->visible($exceedsPending)
                            ->content(function (Get $get) use ($record, $service): HtmlString {
                                $amount = round((float) ($get('amount') ?? 0), 2);

                                if ($amount <= 0) {
                                    return new HtmlString('<span class="text-sm text-gray-500">Ingresá un monto.</span>');
                                }

                                $plan = $service->previewAllocationPlan($record, $amount, Auth::user());

                                $rows = collect($plan)->map(function (array $line): string {
                                    $fecha = $line['date'] ? \Carbon\Carbon::parse($line['date'])->format('d/m/Y') : '';
                                    $etiqueta = $line['is_overflow']
                                        ? ' <span class="text-warning-600 font-medium">(excede el total — requiere justificación)</span>'
                                        : ($line['is_origin'] ? ' <span class="text-gray-500">(este manifiesto)</span>' : '');

                                    return sprintf(
                                        '<li class="flex justify-between gap-4 py-1 border-b border-gray-200 dark:border-gray-700">'.
                                        '<span>#%s <span class="text-gray-500 text-xs">%s</span>%s</span>'.
                                        '<span class="font-semibold">HNL %s</span></li>',
                                        e($line['number']),
                                        e($fecha),
                                        $etiqueta,
                                        number_format($line['amount'], 2)
                                    );
                                })->implode('');

                                return new HtmlString(
                                    '<ul class="text-sm w-full">'.$rows.'</ul>'.
                                    '<p class="text-xs text-gray-500 mt-2">Estimado al día de hoy. '.
                                    'Si otro usuario deposita antes de que guardes, el reparto se recalcula al guardar.</p>'
                                );
                            })
                            ->columnSpanFull(),

                        Textarea::make('justification')
                            ->label('Justificación del depósito en exceso')
                            ->visible($exceedsPending)
                            ->required($exceedsPending)
                            ->minLength(15)
                            ->maxLength(1000)
                            ->rows(2)
                            ->placeholder('Ej.: una sola transferencia para cubrir los manifiestos 785569 y 784907.')
                            ->helperText('Obligatoria. Queda registrada en la auditoría junto con el reparto.')
                            ->columnSpanFull(),

                        DatePicker::make('deposit_date')
                            ->label('Fecha de Depósito')
                            ->required()
                            ->default(today())
                            ->maxDate(today()),

                        Select::make('bank')
                            ->label('Banco')
                            ->options(\App\Models\Deposit::bankOptions())
                            ->native(false)
                            ->searchable()
                            ->placeholder('Seleccioná el banco'),

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
                            ->helperText('Opcional. Cualquier formato (JPG, PNG, WEBP) se convierte automáticamente a WebP optimizado. Máx. 8 MB.')
                            ->image()
                            ->imageEditor()
                            ->disk('local')
                            ->directory('deposits/receipts')
                            ->visibility('private')
                            ->maxSize(8192)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            // Conversión a WebP (resize 1400 + calidad 85) vía el
                            // Service — igual que DepositForm y el RelationManager,
                            // para que TODA imagen subida quede en WebP optimizado.
                            ->saveUploadedFileUsing(
                                fn ($file): string => app(\App\Services\ReceiptImageService::class)->convertToWebp($file)
                            )
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

                    // El cuerpo detalla el reparto cuando la boleta tocó más de
                    // un manifiesto: sin esto el operador vería "HNL 5,000
                    // registrados" y no sabría que 0.32 se fueron a tapar el
                    // manifiesto de la semana pasada.
                    $deposit->load('allocations.manifest:id,number');

                    $detalle = $deposit->allocations->count() > 1
                        ? $deposit->allocations
                            ->map(fn ($a) => '#'.$a->manifest->number.': HNL '.number_format((float) $a->amount, 2))
                            ->implode(' · ')
                        : ($deposit->bank ?? 'Sin banco');

                    Notification::make()
                        ->title('Depósito registrado correctamente')
                        ->body('HNL '.number_format($deposit->amount, 2).' — '.$detalle)
                        ->success()
                        ->send();
                }),

            // ── Ajustar Diferencia (centavos) ───────────────────────────
            // Un depósito bancario rara vez cuadra al centavo, y el cierre
            // exige diferencia CERO exacta. Sin esta acción, un manifiesto con
            // -0.01 queda varado de por vida (así estaba producción: 114
            // manifiestos activos, 0 cerrados).
            //
            // Deliberadamente NO es una tolerancia automática de cierre: cada
            // ajuste lo firma un usuario con permiso Adjust:Manifest, con
            // motivo y registro en el canal `finance`. isReadyToClose() sigue
            // exigiendo cero — el ajuste solo es la vía auditable de llegar ahí.
            Action::make('ajustar_diferencia')
                ->label('Ajustar Diferencia')
                ->icon('heroicon-o-scale')
                ->color('warning')
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    $difference = round((float) $this->record->difference, 2);

                    return ! $this->record->isClosed()
                        && $difference != 0.0
                        && abs($difference) <= app(ManifestAdjustmentService::class)->maxAmount()
                        && $user->can('adjust', $this->record);
                })
                ->modalHeading('Ajustar diferencia por centavos')
                ->modalDescription(function (): string {
                    $difference = (float) $this->record->difference;

                    return $difference > 0
                        ? 'Faltan HNL '.number_format($difference, 2).' por depositar. El ajuste los da por recibidos y deja el manifiesto en cero.'
                        : 'Sobran HNL '.number_format(abs($difference), 2).' depositados. El ajuste los da por buenos y deja el manifiesto en cero.';
                })
                ->modalSubmitActionLabel('Registrar ajuste')
                ->modalWidth('lg')
                ->schema(function (): array {
                    $tope = app(ManifestAdjustmentService::class)->maxAmount();

                    return [
                        TextInput::make('amount')
                            ->label('Monto del ajuste')
                            ->required()
                            ->numeric()
                            ->prefix('HNL')
                            // Prellenado con la diferencia exacta: el caso normal
                            // es aceptarla tal cual y dejar el manifiesto en cero.
                            ->default(round((float) $this->record->difference, 2))
                            ->helperText(
                                'Positivo: se da por recibido un faltante. Negativo: se da por bueno un sobrante. '.
                                'Tope por ajuste: HNL '.number_format($tope, 2).'.'
                            ),

                        Textarea::make('reason')
                            ->label('Motivo del ajuste')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500)
                            ->rows(3)
                            ->placeholder('Ej.: diferencia de redondeo de la transferencia del 28/07, verificada contra el estado de cuenta.')
                            ->helperText('Queda registrado con tu nombre y la hora en la auditoría.'),
                    ];
                })
                ->action(function (array $data): void {
                    app(ManifestAdjustmentService::class)->adjust(
                        $this->record,
                        (float) $data['amount'],
                        (string) $data['reason'],
                        Auth::id()
                    );

                    $this->record->refresh();

                    Notification::make()
                        ->title('Ajuste registrado')
                        ->body('Nueva diferencia: HNL '.number_format((float) $this->record->difference, 2))
                        ->success()
                        ->send();

                    $this->refreshFormData(['difference', 'adjustment_amount', 'total_deposited']);
                }),

            // ── Reportes de facturas ────────────────────────────────────
            // Cada botón tiene su permiso custom (ManifestPolicy →
            // ExportInvoicesPdf/ExportProductsPdf/ExportChecklistPdf:Manifest),
            // administrable desde Shield → Permisos personalizados.
            ActionGroup::make([
                Action::make('report_facturas_pdf')
                    ->label('Reporte PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('danger')
                    ->visible(fn (): bool => Auth::user()->can('exportInvoicesPdf', $this->record))
                    ->action(function (): void {
                        /** @var User $user */
                        $user = Auth::user();

                        $payloadData = [
                            'manifest_id' => $this->record->id,
                        ];

                        // Si el usuario tiene bodega asignada, filtrar solo sus facturas
                        if ($user->isWarehouseUser()) {
                            $payloadData['warehouse_ids'] = $user->warehouseIds();
                        }

                        $payload = Crypt::encryptString(json_encode($payloadData));

                        $this->js("window.open('/imprimir/reportes/facturas?payload=".urlencode($payload)."', '_blank')");
                    }),

                Action::make('report_productos_pdf')
                    ->label('Sublista Productos')
                    ->icon('heroicon-o-cube')
                    ->color('warning')
                    ->visible(fn (): bool => Auth::user()->can('exportProductsPdf', $this->record))
                    ->action(function (): void {
                        /** @var User $user */
                        $user = Auth::user();

                        $payloadData = [
                            'manifest_id' => $this->record->id,
                        ];

                        if ($user->isWarehouseUser()) {
                            $payloadData['warehouse_ids'] = $user->warehouseIds();
                        }

                        $payload = Crypt::encryptString(json_encode($payloadData));

                        $this->js("window.open('/imprimir/reportes/productos?payload=".urlencode($payload)."', '_blank')");
                    }),

                Action::make('report_facturas_checklist')
                    ->label('Sublista Facturas')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('success')
                    ->visible(fn (): bool => Auth::user()->can('exportChecklistPdf', $this->record))
                    ->action(function (): void {
                        /** @var User $user */
                        $user = Auth::user();

                        $payloadData = [
                            'manifest_id' => $this->record->id,
                        ];

                        if ($user->isWarehouseUser()) {
                            $payloadData['warehouse_ids'] = $user->warehouseIds();
                        }

                        $payload = Crypt::encryptString(json_encode($payloadData));

                        $this->js("window.open('/imprimir/reportes/facturas-checklist?payload=".urlencode($payload)."', '_blank')");
                    }),

            ])
                ->label('Facturas')
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray')
                ->button()
                // El grupo aparece si el usuario puede ver AL MENOS un botón.
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();

                    return $user->can('exportInvoicesPdf', $this->record)
                        || $user->can('exportProductsPdf', $this->record)
                        || $user->can('exportChecklistPdf', $this->record);
                }),

            // ── Reporte PDF de devoluciones ─────────────────────────────
            // Jaremar consume los datos vía API; Hozana genera el PDF.
            // El modal permite filtrar por período antes de imprimir.
            // Permiso custom ExportReturnsPdf:Manifest (ManifestPolicy).
            Action::make('report_devoluciones_pdf')
                ->label('Devoluciones')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->button()
                ->visible(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();

                    return $this->record->returns()->exists() && $user->can('exportReturnsPdf', $this->record);
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
                            'all' => 'Todas las devoluciones',
                            'today' => 'Hoy',
                            'week' => 'Esta semana',
                            'month' => 'Este mes',
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
                        'today' => [now()->startOfDay()->format('Y-m-d'), now()->endOfDay()->format('Y-m-d')],
                        'week' => [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')],
                        'month' => [now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d')],
                        'custom' => [$data['date_from'], $data['date_to']],
                        default => [null, null],
                    };

                    $payload = Crypt::encryptString(json_encode([
                        'manifest_id' => $this->record->id,
                        'date_from' => $from,
                        'date_to' => $to,
                        'period' => $data['period'],
                    ]));

                    $this->js("window.open('/imprimir/reportes/devoluciones?payload=".urlencode($payload)."', '_blank')");
                }),
        ];
    }
}
