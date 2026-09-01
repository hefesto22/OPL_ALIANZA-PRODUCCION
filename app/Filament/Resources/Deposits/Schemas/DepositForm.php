<?php

namespace App\Filament\Resources\Deposits\Schemas;

use App\Models\Deposit;
use App\Models\Manifest;
use App\Services\DepositService;
use App\Services\ReceiptImageService;
use App\Support\WarehouseScope;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class DepositForm
{
    public static function make(Schema $schema): Schema
    {
        // Closure compartida: se invoca al cargar el form (afterStateHydrated)
        // para que los totales aparezcan en edit mode, y al cambiar el Select
        // (afterStateUpdated) para que se actualicen al elegir otro manifest.
        // Antes solo se cargaba en la segunda — los totales aparecían en "—"
        // al abrir la pantalla de Editar Depósito.
        $loadTotals = function (mixed $state, Set $set): void {
            if (! $state) {
                $set('_manifest_total', null);
                $set('_manifest_deposited', null);
                $set('_manifest_pending', null);

                return;
            }

            $manifest = Manifest::find($state);
            if (! $manifest) {
                return;
            }

            // El pendiente sale de `difference`, que ya descuenta depósitos
            // Y ajustes manuales de centavos. Restar total_deposited a mano
            // ignoraría los ajustes y mostraría un saldo que no existe.
            $pending = max(0, (float) $manifest->difference);

            $set('_manifest_total', 'HNL '.number_format($manifest->total_to_deposit, 2));
            $set('_manifest_deposited', 'HNL '.number_format($manifest->total_deposited, 2));
            $set('_manifest_pending', 'HNL '.number_format($pending, 2));
        };

        return $schema->components([

            // ── Selección de manifiesto ────────────────────────────────
            Section::make('Seleccionar Manifiesto')
                ->description('Solo se muestran manifiestos con saldo pendiente de depósito.')
                ->schema([
                    Select::make('manifest_id')
                        ->label('Manifiesto')
                        ->required()
                        ->rules(['exists:manifests,id'])
                        // En edición el manifest del depósito queda bloqueado:
                        // cambiarlo descalabra el total_deposited de ambos
                        // manifests (el original pierde el depósito, el nuevo
                        // lo gana sin trazabilidad). Si el operador necesita
                        // mover un depósito, debe cancelar el actual y crear
                        // uno nuevo.
                        ->disabled(fn (?Deposit $record): bool => $record !== null)
                        ->options(function (?Deposit $record) {
                            // Manifiestos con saldo pendiente, filtrados por bodega del usuario
                            $query = Manifest::query()
                                ->whereIn('status', ['imported', 'processing'])
                                // `difference > 0` en vez de comparar
                                // total_to_deposit contra total_deposited: la
                                // diferencia ya contempla los ajustes de centavos,
                                // así que un manifiesto ajustado a cero deja de
                                // ofrecerse (antes seguía apareciendo con saldo).
                                ->where('difference', '>', 0)
                                ->orderBy('date', 'desc');

                            // Usuarios de bodega solo ven sus propios manifiestos
                            WarehouseScope::apply($query);

                            $manifests = $query->get();

                            // En edición: asegurar que el manifiesto actual siempre aparezca
                            if ($record && $record->manifest_id) {
                                $current = Manifest::find($record->manifest_id);
                                if ($current && ! $manifests->contains('id', $current->id)) {
                                    $manifests->prepend($current);
                                }
                            }

                            return $manifests->mapWithKeys(fn ($m) => [
                                $m->id => sprintf(
                                    '#%s  |  %s  |  Pendiente: HNL %s',
                                    $m->number,
                                    Carbon::parse($m->date)->format('d/m/Y'),
                                    number_format(max(0, (float) $m->difference), 2)
                                ),
                            ]);
                        })
                        ->live()
                        ->afterStateHydrated(fn ($state, Set $set) => $loadTotals($state, $set))
                        ->afterStateUpdated(fn ($state, Set $set) => $loadTotals($state, $set))
                        ->columnSpanFull(),

                    // ── Resumen informativo del manifiesto ────────────
                    // El Grid se oculta en create mode cuando el operador
                    // aún no eligió manifest; en edit mode siempre se ve
                    // porque ya hay record con manifest asociado.
                    Grid::make(3)
                        ->schema([
                            TextInput::make('_manifest_total')
                                ->label('Total a Depositar')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('—'),

                            TextInput::make('_manifest_deposited')
                                ->label('Ya Depositado')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('—'),

                            TextInput::make('_manifest_pending')
                                ->label('Saldo Pendiente')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('—'),
                        ])
                        ->hidden(fn (Get $get, ?Deposit $record): bool => ! $get('manifest_id') && ! $record),
                ]),

            // ── Datos del depósito ─────────────────────────────────────
            // Misma lógica: oculto en create sin manifest, visible siempre
            // en edit mode (donde el record garantiza manifest asociado).
            Section::make('Datos del Depósito')
                ->hidden(fn (Get $get, ?Deposit $record): bool => ! $get('manifest_id') && ! $record)
                ->schema([
                    Grid::make(2)->schema([

                        // ── Bodega del depósito ──────────────────────────
                        // Solo aparece cuando el manifiesto elegido tiene
                        // facturas de varias bodegas y el usuario abarca más
                        // de una. En el caso normal (manifiesto de una sola
                        // bodega) el servicio la deduce y este campo no se ve.
                        Select::make('warehouse_id')
                            ->label('Bodega del depósito')
                            ->native(false)
                            ->options(function (Get $get): array {
                                $manifest = Manifest::find($get('manifest_id'));

                                return $manifest === null
                                    ? []
                                    : app(DepositService::class)->warehouseOptions($manifest, Auth::user());
                            })
                            ->visible(fn (Get $get): bool => self::needsWarehouse($get))
                            ->required(fn (Get $get): bool => self::needsWarehouse($get))
                            ->columnSpan(2)
                            ->helperText(
                                'Este manifiesto tiene facturas de varias bodegas. Indicá a cuál corresponde '.
                                'esta boleta para que el desglose por bodega la cuente donde va.'
                            ),

                        TextInput::make('amount')
                            ->label('Monto a Depositar')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('HNL')
                            ->placeholder('0.00')
                            ->live(onBlur: true)
                            ->helperText(
                                'Menor al saldo para un depósito parcial. Mayor al saldo si transfirieron '.
                                'de más: el exceso queda como sobrepago del manifiesto.'
                            ),

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
                            ->rows(3)
                            ->columnSpan(2)
                            ->placeholder('Notas adicionales...'),

                        // ── Justificación del exceso ──────────────────────
                        // Aparece solo cuando el monto supera el saldo. Se
                        // revalida en DepositService: la UI se puede saltar,
                        // el servicio no.
                        Textarea::make('justification')
                            ->label('Justificación del depósito en exceso')
                            ->visible(fn (Get $get): bool => self::exceedsPending($get))
                            ->required(fn (Get $get): bool => self::exceedsPending($get))
                            ->minLength(15)
                            ->maxLength(1000)
                            ->rows(2)
                            ->columnSpan(2)
                            ->placeholder('Ej.: transferencia redondeada a pedido del encargado de bodega.')
                            ->helperText('Obligatoria. Queda registrada en la auditoría con tu nombre y la hora.'),
                    ]),

                    // ── Comprobante de depósito ────────────────────────
                    // Mismo patrón que DepositsRelationManager: conversión
                    // automática a WebP optimizado vía ReceiptImageService.
                    //
                    // NOTA: este bloque está duplicado entre DepositForm
                    // y DepositsRelationManager::getDepositFormSchema().
                    // Refactorizar a componente compartido es deuda 🟡
                    // — mientras tanto, mantener sincronizados a mano.
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
                            fn ($file): string => app(ReceiptImageService::class)->convertToWebp($file)
                        )
                        ->nullable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * ¿Hay que preguntar la bodega del depósito?
     *
     * Solo cuando el servicio no puede deducirla del manifiesto ni del usuario
     * (ver DepositService::resolveWarehouseId) y además hay opciones que
     * ofrecer. Sin manifiesto elegido todavía, no.
     */
    private static function needsWarehouse(Get $get): bool
    {
        $manifest = Manifest::find($get('manifest_id'));

        if ($manifest === null) {
            return false;
        }

        $service = app(DepositService::class);

        return $service->resolveWarehouseId($manifest, Auth::user()) === null
            && $service->warehouseOptions($manifest, Auth::user()) !== [];
    }

    /**
     * ¿El monto tecleado supera el saldo pendiente del manifiesto elegido?
     *
     * Se lee `difference` (ya neta de depósitos y ajustes) en vez de
     * recalcular a mano. Devuelve false sin manifiesto o sin monto para que
     * los campos condicionales no parpadeen mientras se llena el formulario.
     */
    private static function exceedsPending(Get $get): bool
    {
        $manifestId = $get('manifest_id');
        $amount = round((float) ($get('amount') ?? 0), 2);

        if (! $manifestId || $amount <= 0) {
            return false;
        }

        $manifest = Manifest::find($manifestId);

        return $manifest !== null && $amount > round(max(0, (float) $manifest->difference), 2);
    }
}
