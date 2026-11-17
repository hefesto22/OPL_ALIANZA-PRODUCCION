<?php

namespace App\Filament\Resources\Manifests\Pages\Actions;

use App\Filament\Resources\Returns\Schemas\FinalReturnConfirmation;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Services\ReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class RegistrarDevolucionAction
{
    public static function make(): Action
    {
        return Action::make('registrar_devolucion')
            ->label('Devolver')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(function (Invoice $record): bool {
                if (! in_array($record->status, ['imported', 'partial_return'])) {
                    return false;
                }

                // Ventana hábil cerrada: el botón solo lo ve quien tiene el
                // permiso de excepción (finanzas / super_admin). Antes se
                // mostraba a todos y el service respondía con un toast — el
                // usuario llenaba el modal para nada.
                //
                // El memo estático evita el N+1: la tabla lista facturas de UN
                // solo manifiesto, así que son N filas contra 1 sola query.
                static $manifests = [];
                $manifest = $manifests[$record->manifest_id] ??= Manifest::find($record->manifest_id);

                if ($manifest?->returnsWindowClosed()
                    && ! Auth::user()?->can(ReturnService::PERMISSION_AFTER_DEADLINE)) {
                    return false;
                }

                return app(ReturnService::class)->hasAvailableLines($record);
            })
            ->mountUsing(function ($form, Invoice $record): void {
                $record->load('lines');
                $returnService = app(ReturnService::class);

                $lineIds = $record->lines->pluck('id')->toArray();
                $returnedByLine = $returnService->getReturnedQuantitiesForLines($lineIds);

                $lines = $record->lines->map(function ($line) use ($returnedByLine): array {
                    $alreadyReturned = (float) ($returnedByLine[$line->id] ?? 0);
                    $available = max(0, (float) $line->quantity_fractions - $alreadyReturned);
                    $convFactor = max(1, (int) ($line->conversion_factor ?? 1));
                    $unitSale = strtoupper($line->unit_sale ?? 'UN');
                    // Precio CON ISV (lo realmente facturado al cliente), no la base
                    // sin impuesto. Mantiene el modal consistente con el total que
                    // ReturnService persiste. Ver InvoiceLine::unitPriceWithTax.
                    $unitPrice = $line->unitPriceWithTax();

                    $availableBoxes = ($unitSale === 'CJ')
                        ? (int) floor($available / $convFactor)
                        : 0;

                    $pricePerBox = ($unitSale === 'CJ')
                        ? round($convFactor * $unitPrice, 2)
                        : 0.0;

                    return [
                        'invoice_line_id' => $line->id,
                        'line_number' => $line->line_number,
                        'product_id' => $line->product_id,
                        'product_description' => $line->product_description,
                        'unit_sale' => $unitSale,
                        'conversion_factor' => $convFactor,
                        'quantity_box' => 0,
                        'quantity' => 0,
                        'available_quantity' => $available,
                        'available_boxes' => $availableBoxes,
                        'unit_price' => $unitPrice,
                        'price_per_box' => $pricePerBox,
                        'line_total' => 0,
                    ];
                })->toArray();

                // Disponible = total factura − lo ya devuelto en otras devoluciones
                $alreadyReturnedTotal = $record->returns()
                    ->whereIn('status', ['approved', 'pending'])
                    ->sum('total');
                $availableTotal = max(0, round((float) $record->total - (float) $alreadyReturnedTotal, 2));

                $form->fill([
                    'return_date' => now()->toDateString(),
                    'invoice_number' => $record->invoice_number,
                    'client_name' => $record->client_name,
                    'invoice_total' => round((float) $record->total, 2),
                    'available_total' => $availableTotal,
                    'lines' => $lines,
                ]);
            })
            ->modalHeading(fn (Invoice $record): string => 'Devolver factura '.$record->invoice_number)
            ->modalDescription(fn (Invoice $record): string => (string) $record->client_name)
            ->schema([
                // Campos ocultos globales del encabezado
                Hidden::make('invoice_number'),
                Hidden::make('client_name'),
                Hidden::make('invoice_total'),
                Hidden::make('available_total'),
                // Siempre hoy: se muestra en la barra de la factura, no como campo.
                Hidden::make('return_date'),

                // ── Aviso de excepción fuera de ventana ─────────────────
                // Solo lo ve quien llegó hasta acá con el manifiesto ya
                // cerrado (permiso RegisterAfterDeadline): debe saber que
                // Jaremar no la recibe salvo que re-consulte la fecha de
                // emisión de la factura.
                Placeholder::make('after_deadline_warning')
                    ->hiddenLabel()
                    ->visible(fn (Invoice $record): bool => (bool) $record->manifest?->returnsWindowClosed())
                    ->content(fn (Invoice $record): HtmlString => self::avisoFueraDePlazoHtml($record)),

                // ── Barra de la factura: total, ya devuelto, disponible ─
                Placeholder::make('invoice_header')
                    ->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => self::barraFacturaHtml($get)),

                // Código primero: las bodegas buscan "BE-01" tecleando.
                Select::make('return_reason_id')
                    ->label('Motivo de devolución')
                    ->required()
                    ->options(fn (): array => ReturnReason::query()
                        ->where('is_active', true)
                        ->orderBy('code')
                        ->get(['id', 'code', 'description'])
                        ->mapWithKeys(fn (ReturnReason $reason): array => [
                            $reason->id => "{$reason->code} — {$reason->description}",
                        ])
                        ->all())
                    ->searchable(),

                Section::make('Productos a devolver')
                    ->compact()
                    ->headerActions([
                        // ── Devolver Todo ─────────────────────────────────
                        Action::make('devolver_todo')
                            ->label('Devolver todo')
                            ->icon('heroicon-o-arrow-uturn-left')
                            ->color('danger')
                            ->action(function (Get $get, Set $set): void {
                                $lines = $get('lines');
                                foreach ($lines as $key => $line) {
                                    $unitSale = strtoupper($line['unit_sale'] ?? 'UN');
                                    $convFactor = max(1, (int) ($line['conversion_factor'] ?? 1));
                                    $price = (float) ($line['unit_price'] ?? 0);

                                    if ($unitSale === 'CJ') {
                                        $available = (float) ($line['available_quantity'] ?? 0);
                                        $maxBoxes = (int) floor($available / $convFactor);
                                        $looseUnits = $available - ($maxBoxes * $convFactor);
                                        $lines[$key]['quantity_box'] = $maxBoxes;
                                        $lines[$key]['quantity'] = max(0, round($looseUnits, 4));
                                        $lines[$key]['line_total'] = round($available * $price, 2);
                                    } else {
                                        $maxUnits = (float) ($line['available_quantity'] ?? 0);
                                        $lines[$key]['quantity'] = $maxUnits;
                                        $lines[$key]['quantity_box'] = 0;
                                        $lines[$key]['line_total'] = round($maxUnits * $price, 2);
                                    }
                                }
                                $set('lines', $lines);
                            }),

                        // ── Limpiar Todo ──────────────────────────────────
                        Action::make('limpiar_todo')
                            ->label('Limpiar')
                            ->icon('heroicon-o-x-circle')
                            ->color('gray')
                            ->action(function (Get $get, Set $set): void {
                                $lines = $get('lines');
                                foreach ($lines as $key => $line) {
                                    $lines[$key]['quantity'] = 0;
                                    $lines[$key]['quantity_box'] = 0;
                                    $lines[$key]['line_total'] = 0;
                                }
                                $set('lines', $lines);
                            }),
                    ])
                    ->schema([
                        // Una fila por producto (tabla). En pantallas angostas
                        // Filament la apila sola con sus etiquetas.
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Producto'),
                                TableColumn::make('Disponible')->width('8rem'),
                                TableColumn::make('A devolver')->width('15rem'),
                                TableColumn::make('Subtotal')->width('8rem')->alignEnd(),
                            ])
                            ->schema([
                                // ── Ocultos (los usa ReturnService al guardar) ──
                                Hidden::make('invoice_line_id'),
                                Hidden::make('line_number'),
                                Hidden::make('product_id'),
                                Hidden::make('product_description'),
                                Hidden::make('available_quantity'),
                                Hidden::make('available_boxes'),
                                Hidden::make('conversion_factor'),
                                Hidden::make('unit_price'),
                                Hidden::make('price_per_box'),
                                Hidden::make('unit_sale'),
                                Hidden::make('line_total'),

                                // ── Columnas visibles, en el orden de la tabla ──
                                Placeholder::make('producto')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::productoHtml($get)),

                                Placeholder::make('disponible')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::disponibleHtml($get)),

                                Flex::make([
                                    // ── Cajas (solo CJ) ───────────────────
                                    TextInput::make('quantity_box')
                                        ->label('Cajas')
                                        ->suffix('cj')
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->live(debounce: 500)
                                        ->disabled(fn (Get $get): bool => (float) ($get('available_quantity') ?? 0) <= 0)
                                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                            $boxes = max(0, (int) $state);
                                            $maxBoxes = (int) ($get('available_boxes') ?? 0);
                                            $convFactor = max(1, (int) ($get('conversion_factor') ?? 1));
                                            $price = (float) ($get('unit_price') ?? 0);
                                            $looseUnits = max(0, (float) $get('quantity'));

                                            if ($boxes > $maxBoxes) {
                                                $boxes = $maxBoxes;
                                                $set('quantity_box', $boxes);
                                            }

                                            $totalFractions = ($boxes * $convFactor) + $looseUnits;
                                            $available = (float) $get('available_quantity');
                                            if ($totalFractions > $available) {
                                                $looseUnits = max(0, $available - ($boxes * $convFactor));
                                                $set('quantity', $looseUnits);
                                            }

                                            $set('line_total', round((($boxes * $convFactor) + $looseUnits) * $price, 2));
                                        })
                                        ->hidden(fn (Get $get) => ($get('unit_sale') ?? 'UN') !== 'CJ'),

                                    // ── Unidades sueltas (CJ) o Cantidad (UN) ──
                                    TextInput::make('quantity')
                                        ->label(fn (Get $get) => ($get('unit_sale') ?? 'UN') === 'CJ' ? 'Unidades sueltas' : 'Unidades')
                                        ->suffix('ud')
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->live(debounce: 500)
                                        ->disabled(fn (Get $get): bool => (float) ($get('available_quantity') ?? 0) <= 0)
                                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                            $units = max(0, (float) $state);
                                            $price = (float) ($get('unit_price') ?? 0);
                                            $isCJ = ($get('unit_sale') ?? 'UN') === 'CJ';

                                            if ($isCJ) {
                                                $convFactor = max(1, (float) $get('conversion_factor'));
                                                $boxes = max(0, (float) $get('quantity_box'));
                                                $available = (float) $get('available_quantity');
                                                $maxLoose = max(0, $available - ($boxes * $convFactor));

                                                if ($units > $maxLoose) {
                                                    $units = $maxLoose;
                                                    $set('quantity', $units);
                                                }

                                                $set('line_total', round((($boxes * $convFactor) + $units) * $price, 2));
                                            } else {
                                                $maxQty = (float) $get('available_quantity');
                                                if ($units > $maxQty) {
                                                    $units = $maxQty;
                                                    $set('quantity', $units);
                                                }

                                                $set('line_total', round($units * $price, 2));
                                            }
                                        }),
                                ]),

                                Placeholder::make('subtotal')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::subtotalHtml($get)),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),

                        // ── Total de lo que se está devolviendo ───────────────
                        Placeholder::make('resumen_devolucion')
                            ->hiddenLabel()
                            ->live()
                            ->content(fn (Get $get): HtmlString => self::resumenHtml($get('lines') ?? [])),
                    ]),

                // ── Definitiva al guardar: aviso + casilla ─────────────
                ...FinalReturnConfirmation::components(
                    fn (Invoice $record): bool => app(ReturnService::class)->registersAsFinal($record)
                ),
            ])
            ->action(function (array $data, Invoice $record): void {
                $hasLines = collect($data['lines'] ?? [])
                    ->filter(fn ($l) => (float) ($l['quantity_box'] ?? 0) > 0 ||
                        (float) ($l['quantity'] ?? 0) > 0
                    )
                    ->isNotEmpty();

                if (! $hasLines) {
                    Notification::make()
                        ->title('Debes ingresar al menos un producto a devolver.')
                        ->warning()->send();

                    return;
                }

                try {
                    app(ReturnService::class)->createReturn([
                        'invoice_id' => $record->id,
                        'return_reason_id' => $data['return_reason_id'],
                        'return_date' => $data['return_date'],
                        'lines' => $data['lines'],
                        'created_by' => Auth::id(),
                    ]);
                } catch (ValidationException $e) {
                    $firstError = collect($e->errors())->flatten()->first()
                        ?? 'Error de validación al registrar la devolución.';

                    Notification::make()
                        ->title('No se pudo registrar la devolución')
                        ->body($firstError)
                        ->danger()
                        ->send();

                    return;
                } catch (\RuntimeException $e) {
                    Notification::make()
                        ->title('No se pudo registrar la devolución')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Devolución registrada correctamente.')
                    ->success()->send();
            })
            ->modalWidth('5xl')
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Registrar devolución');
    }

    // ─── HTML de las celdas y barras del modal ────────────────────
    // Estilos en línea con colores neutros (currentColor + opacidad):
    // se leen igual en modo claro y oscuro.

    private static function barraFacturaHtml(Get $get): HtmlString
    {
        $total = (float) ($get('invoice_total') ?? 0);
        $disponible = (float) ($get('available_total') ?? 0);
        $devuelto = max(0, $total - $disponible);
        $fecha = filled($get('return_date')) ? Carbon::parse($get('return_date'))->format('d/m/Y') : '';

        $dato = fn (string $etiqueta, string $valor, string $estilo = ''): string => '<div>'.
            "<span style=\"font-size:12px;opacity:.65;\">{$etiqueta}</span> ".
            "<strong style=\"font-size:15px;{$estilo}\">{$valor}</strong></div>";

        return new HtmlString(
            '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px 28px;padding:10px 14px;'.
            'border:1px solid rgba(127,127,127,.25);border-radius:10px;">'.
            $dato('Total factura', self::lempiras($total)).
            $dato('Ya devuelto', self::lempiras($devuelto)).
            $dato('Disponible', self::lempiras($disponible), 'color:#22c55e;').
            "<div style=\"margin-left:auto;font-size:12px;opacity:.65;\">Fecha de devolución: hoy {$fecha}</div>".
            '</div>'
        );
    }

    private static function avisoFueraDePlazoHtml(Invoice $record): HtmlString
    {
        $cierre = e($record->manifest->returnsDeadlineLabel());

        return new HtmlString(
            '<div style="border:1px solid #f59e0b;background:rgba(245,158,11,.08);border-radius:8px;'.
            'padding:8px 12px;font-size:13px;line-height:1.5;">'.
            '<strong style="color:#f59e0b;">Fuera de plazo.</strong> '.
            "La ventana cerró el {$cierre}. Se registra como <strong>excepción</strong> y queda en la bitácora; ".
            'Jaremar solo la recibe si vuelve a consultar la fecha de emisión de esta factura.'.
            '</div>'
        );
    }

    private static function productoHtml(Get $get): HtmlString
    {
        $descripcion = e($get('product_description') ?? '');
        $codigo = e(ltrim((string) ($get('product_id') ?? ''), '0'));
        $linea = e((string) ($get('line_number') ?? ''));
        $precio = (float) ($get('unit_price') ?? 0);
        $factor = max(1, (int) ($get('conversion_factor') ?? 1));
        $esCaja = ($get('unit_sale') ?? 'UN') === 'CJ';

        $detalle = $precio > 0
            ? self::lempiras($precio).'/ud'.($esCaja ? " · {$factor} ud por caja" : '')
            : '<span style="color:#f59e0b;font-weight:600;">Bonificación</span>';

        return new HtmlString(
            '<div style="line-height:1.35;">'.
            "<div style=\"font-weight:600;\">{$descripcion}</div>".
            "<div style=\"font-size:12px;opacity:.65;margin-top:2px;\">#{$linea} · {$codigo} · {$detalle}</div>".
            '</div>'
        );
    }

    private static function disponibleHtml(Get $get): HtmlString
    {
        $disponible = (float) ($get('available_quantity') ?? 0);

        if ($disponible <= 0) {
            return new HtmlString('<span style="font-size:12px;opacity:.5;">Ya devuelto</span>');
        }

        if (($get('unit_sale') ?? 'UN') !== 'CJ') {
            return new HtmlString(self::cantidad($disponible).' ud');
        }

        $factor = max(1, (int) ($get('conversion_factor') ?? 1));
        $cajas = (int) floor($disponible / $factor);
        $sueltas = $disponible - ($cajas * $factor);

        return new HtmlString($cajas.' cj'.($sueltas > 0 ? ' + '.self::cantidad($sueltas).' ud' : ''));
    }

    private static function subtotalHtml(Get $get): HtmlString
    {
        $total = (float) ($get('line_total') ?? 0);

        return new HtmlString($total > 0
            ? '<strong>'.self::lempiras($total).'</strong>'
            : '<span style="opacity:.4;">—</span>');
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     */
    private static function resumenHtml(array $lines): HtmlString
    {
        $seleccionadas = collect($lines)->filter(
            fn ($line): bool => (float) ($line['quantity_box'] ?? 0) > 0 || (float) ($line['quantity'] ?? 0) > 0
        );

        if ($seleccionadas->isEmpty()) {
            return new HtmlString(
                '<div style="font-size:13px;opacity:.6;padding:2px;">'.
                'Escriba en «A devolver» las cantidades que regresan, o use «Devolver todo».</div>'
            );
        }

        $cuantos = $seleccionadas->count();
        $total = (float) $seleccionadas->sum(fn ($line): float => (float) ($line['line_total'] ?? 0));

        return new HtmlString(
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;'.
            'padding:10px 14px;border:1px solid rgba(127,127,127,.25);border-radius:10px;">'.
            '<span style="font-size:13px;">Devolviendo <strong>'.$cuantos.' '.($cuantos === 1 ? 'producto' : 'productos').'</strong></span>'.
            '<span style="font-size:13px;">Total a devolver '.
            '<strong style="font-size:18px;color:#ef4444;margin-left:6px;">'.self::lempiras($total).'</strong></span>'.
            '</div>'
        );
    }

    private static function lempiras(float $monto): string
    {
        return 'L '.number_format($monto, 2);
    }

    /** 2 → "2", 2.5 → "2.5": las cantidades casi siempre son enteras. */
    private static function cantidad(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ','), '0'), '.');
    }
}
