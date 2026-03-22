<?php

namespace App\Filament\Resources\Returns\Schemas;

use App\Models\Invoice;
use App\Models\ReturnReason;
use App\Models\User;
use App\Services\ReturnService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ReturnForm
{
    /**
     * @param bool $editing  Cuando es true, el campo Factura queda bloqueado
     *                       (no se puede cambiar la factura de una devolución existente)
     *                       y las líneas se pre-cargan vía mutateFormDataBeforeFill()
     *                       en EditReturn, no via afterStateUpdated.
     */
    public static function make(Schema $schema, bool $editing = false): Schema
    {
        $invoiceField = Select::make('invoice_id')
            ->label('Factura')
            ->required()
            ->searchable()
            ->preload(false)
            ->getSearchResultsUsing(function (string $search) {
                /** @var User $user */
                $user = Auth::user();

                return Invoice::query()
                    ->where(function ($q) use ($search) {
                        $q->where('invoice_number', 'like', "%{$search}%")
                          ->orWhere('client_name', 'like', "%{$search}%");
                    })
                    ->when($user->isWarehouseUser(), fn($q) =>
                        $q->where('warehouse_id', $user->warehouse_id)
                    )
                    ->whereIn('status', ['imported', 'partial_return'])
                    ->limit(20)
                    ->get()
                    ->mapWithKeys(fn($inv) => [
                        $inv->id => "#{$inv->invoice_number} — {$inv->client_name}"
                    ]);
            })
            ->getOptionLabelUsing(fn($value) => optional(Invoice::find($value))->invoice_number);

        if ($editing) {
            // En modo edición: factura bloqueada, sin afterStateUpdated.
            // Las líneas se pre-cargan en EditReturn::mutateFormDataBeforeFill().
            $invoiceField = $invoiceField->disabled()->dehydrated(true);
        } else {
            // En modo creación: factura seleccionable, carga líneas al cambiar.
            $invoiceField = $invoiceField
                ->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    if (!$state) {
                        $set('lines', []);
                        $set('client_name', null);
                        $set('invoice_number', null);
                        $set('invoice_total', 0);
                        $set('available_total', 0);
                        return;
                    }

                    $invoice = Invoice::with('lines')->find($state);
                    if (!$invoice) return;

                    $returnService  = app(ReturnService::class);
                    $lineIds        = $invoice->lines->pluck('id')->toArray();
                    $returnedByLine = $returnService->getReturnedQuantitiesForLines($lineIds);

                    $lines = $invoice->lines
                        ->map(function ($line) use ($returnedByLine) {
                            $alreadyReturned = (float)($returnedByLine[$line->id] ?? 0);
                            $available       = max(0, (float)$line->quantity_fractions - $alreadyReturned);
                            $convFactor      = max(1, (float)($line->conversion_factor ?? 1));
                            $unitSale        = strtoupper($line->unit_sale ?? 'UN');
                            // Null → usar price; 0 → bonificación (gratis). No usar ?: porque 0 es falsy.
                            $unitPrice       = $line->price_min_sale !== null ? (float)$line->price_min_sale : (float)$line->price;
                            $availableBoxes  = (int) floor($available / $convFactor);
                            $pricePerBox     = round($convFactor * $unitPrice, 2);

                            return [
                                'invoice_line_id'     => $line->id,
                                'line_number'         => $line->line_number,
                                'product_id'          => $line->product_id,
                                'product_description' => $line->product_description,
                                'unit_sale'           => $unitSale,
                                'quantity_box'        => 0,
                                'quantity'            => 0,
                                'available_quantity'  => $available,
                                'available_boxes'     => $availableBoxes,
                                'conversion_factor'   => $convFactor,
                                'unit_price'          => $unitPrice,
                                'price_per_box'       => $pricePerBox,
                                'line_total'          => 0,
                            ];
                        })->values()->toArray();

                    // Disponible = total factura − lo ya devuelto en otras devoluciones
                    $alreadyReturnedTotal = $invoice->returns()
                        ->whereIn('status', ['approved', 'pending'])
                        ->sum('total');
                    $availableTotal = max(0, round((float) $invoice->total - (float) $alreadyReturnedTotal, 2));

                    $set('lines', $lines);
                    $set('client_name', $invoice->client_name);
                    $set('invoice_number', $invoice->invoice_number);
                    $set('invoice_total', round((float) $invoice->total, 2));
                    $set('available_total', $availableTotal);
                    $set('warehouse_id', $invoice->warehouse_id);
                    $set('manifest_id', $invoice->manifest_id);
                });
        }

        $datePicker = DatePicker::make('return_date')
            ->label('Fecha de Devolución')
            ->required()
            ->default(today())
            ->maxDate(today());

        if ($editing) {
            $datePicker = $datePicker->disabled()->dehydrated(true);
        }

        return $schema->columns(1)->components([

            // ── SECTION 1: Datos de la devolución ─────────────────────────────
            Section::make('Datos de la Devolución')
                ->columnSpanFull()
                ->schema([
                    // ── Totales de la factura (badge row) ──────────────────
                    Placeholder::make('invoice_totals_display')
                        ->label('')
                        ->content(function (Get $get): HtmlString {
                            $invoiceNumber   = htmlspecialchars($get('invoice_number')  ?? '');
                            $clientName      = htmlspecialchars($get('client_name')     ?? '');
                            $manifestNumber  = htmlspecialchars($get('manifest_number') ?? '');
                            $invoiceTotal    = (float) ($get('invoice_total')           ?? 0);
                            $availableTotal  = (float) ($get('available_total')         ?? 0);

                            // Sin factura seleccionada: no mostrar nada
                            if (!$invoiceNumber && !$clientName) {
                                return new HtmlString('');
                            }

                            $invoiceTotalFmt   = 'L.' . number_format($invoiceTotal,   2);
                            $availableTotalFmt = 'L.' . number_format($availableTotal, 2);

                            $manifestBadge = $manifestNumber ? "
                                <div style=\"
                                    background:#fefce8;
                                    border:1px solid #fde68a;
                                    border-radius:8px;
                                    padding:6px 16px;
                                    text-align:center;
                                    min-width:140px;
                                \">
                                    <div style=\"font-size:10px;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;\">Manifiesto</div>
                                    <div style=\"font-size:16px;font-weight:700;color:#78350f;\"># {$manifestNumber}</div>
                                </div>
                            " : '';

                            return new HtmlString("
                                <div style=\"display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;padding:2px 0 6px;\">
                                    {$manifestBadge}
                                    <div style=\"
                                        background:#f0fdf4;
                                        border:1px solid #bbf7d0;
                                        border-radius:8px;
                                        padding:6px 16px;
                                        text-align:center;
                                        min-width:140px;
                                    \">
                                        <div style=\"font-size:10px;font-weight:600;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;\">Total Factura</div>
                                        <div style=\"font-size:16px;font-weight:700;color:#15803d;\">{$invoiceTotalFmt}</div>
                                    </div>
                                    <div style=\"
                                        background:#eff6ff;
                                        border:1px solid #bfdbfe;
                                        border-radius:8px;
                                        padding:6px 16px;
                                        text-align:center;
                                        min-width:140px;
                                    \">
                                        <div style=\"font-size:10px;font-weight:600;color:#2563eb;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;\">Disponible p/Dev.</div>
                                        <div style=\"font-size:16px;font-weight:700;color:#1d4ed8;\">{$availableTotalFmt}</div>
                                    </div>
                                </div>
                            ");
                        }),

                    Grid::make(4)->schema([
                        $invoiceField->columnSpan(1),

                        Select::make('return_reason_id')
                            ->label('Motivo de Devolución')
                            ->required()
                            ->options(ReturnReason::orderBy('code')->pluck('description', 'id'))
                            ->searchable()
                            ->columnSpan(1),

                        $datePicker->columnSpan(1),

                        TextInput::make('client_display')
                            ->label('Cliente')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(fn($component, Get $get) => $component->state($get('client_name') ?? '—'))
                            ->columnSpan(1),
                    ]),

                    Hidden::make('client_name'),
                    Hidden::make('invoice_number'),
                    Hidden::make('manifest_number'),
                    Hidden::make('invoice_total'),
                    Hidden::make('available_total'),
                    Hidden::make('warehouse_id'),
                    Hidden::make('manifest_id'),
                ]),

            // ── SECTION 2: Productos a devolver ───────────────────────────────
            Section::make('Productos a Devolver')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('lines')
                        ->label('')
                        ->schema([
                            // ── Campos ocultos ─────────────────────────────
                            Hidden::make('invoice_line_id'),
                            Hidden::make('available_quantity'),
                            Hidden::make('available_boxes'),
                            Hidden::make('conversion_factor'),
                            Hidden::make('unit_price'),
                            Hidden::make('price_per_box'),
                            Hidden::make('unit_sale'),

                            // ── Fila: identificación + cantidades + subtotal ─
                            // Layout 12 cols: # (1) | Código (2) | Descripción (4) | Cantidad (3) | Subtotal (2)
                            Grid::make(12)->schema([
                                TextInput::make('line_number')
                                    ->label('#')
                                    ->disabled()->dehydrated(true)
                                    ->columnSpan(1),

                                TextInput::make('product_id')
                                    ->label('Código')
                                    ->disabled()->dehydrated(true)
                                    ->columnSpan(2),

                                TextInput::make('product_description')
                                    ->label('Descripción')
                                    ->disabled()->dehydrated(true)
                                    ->columnSpan(4),

                                // ── Cajas (solo CJ) ────────────────────────
                                TextInput::make('quantity_box')
                                    ->label('Cajas a Dev.')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(debounce: 500)
                                    ->helperText(function (Get $get): string {
                                        $pricePerBox = (float) ($get('price_per_box') ?? 0);
                                        $maxBoxes    = (int)   ($get('available_boxes') ?? 0);
                                        if ($pricePerBox > 0) {
                                            return 'L.' . number_format($pricePerBox, 2) . '/caja  ·  máx: ' . $maxBoxes;
                                        }
                                        return 'Bonificación  ·  máx: ' . $maxBoxes;
                                    })
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $boxes      = max(0, (float) $state);
                                        $maxBoxes   = (float) $get('available_boxes');
                                        $convFactor = max(1, (float) $get('conversion_factor'));
                                        $price      = (float) $get('unit_price');

                                        if ($boxes > $maxBoxes) {
                                            $boxes = $maxBoxes;
                                            $set('quantity_box', $boxes);
                                        }

                                        $set('line_total', round($boxes * $convFactor * $price, 2));
                                    })
                                    ->hidden(fn(Get $get) => ($get('unit_sale') ?? 'UN') !== 'CJ')
                                    ->columnSpan(3),

                                // ── Unidades (solo UN) ──────────────────────
                                TextInput::make('quantity')
                                    ->label('Cant. a Dev.')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(debounce: 500)
                                    ->helperText(function (Get $get): string {
                                        $unitPrice = (float) ($get('unit_price')       ?? 0);
                                        $maxUnits  = (float) ($get('available_quantity') ?? 0);
                                        if ($unitPrice > 0) {
                                            return 'L.' . number_format($unitPrice, 2) . '/und.  ·  máx: ' . number_format($maxUnits, 0);
                                        }
                                        return 'Bonificación  ·  máx: ' . number_format($maxUnits, 0);
                                    })
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $units    = max(0, (float) $state);
                                        $maxUnits = (float) $get('available_quantity');
                                        $price    = (float) $get('unit_price');

                                        if ($units > $maxUnits) {
                                            $units = $maxUnits;
                                            $set('quantity', $units);
                                        }

                                        $set('line_total', round($units * $price, 2));
                                    })
                                    ->hidden(fn(Get $get) => ($get('unit_sale') ?? 'UN') === 'CJ')
                                    ->columnSpan(3),

                                TextInput::make('line_total')
                                    ->label('Subtotal')
                                    ->prefix('L.')
                                    ->disabled()->dehydrated(true)
                                    ->columnSpan(2),
                            ]),
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columns(1),

                    // ── Resumen reactivo al fondo ───────────────────────────
                    Placeholder::make('resumen_devolucion')
                        ->label('')
                        ->live()
                        ->content(function (Get $get): HtmlString {
                            $lines = $get('lines') ?? [];

                            $selectedLines = collect($lines)->filter(
                                fn ($l) =>
                                    (float) ($l['quantity_box'] ?? 0) > 0 ||
                                    (float) ($l['quantity']     ?? 0) > 0
                            );

                            if ($selectedLines->isEmpty()) {
                                return new HtmlString('
                                    <div style="
                                        display:flex; align-items:center; gap:8px;
                                        padding:12px 16px;
                                        border-radius:8px;
                                        background:#f9fafb;
                                        border:1px dashed #d1d5db;
                                        color:#9ca3af;
                                        font-size:13px;
                                        font-style:italic;
                                    ">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="flex-shrink:0">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                        </svg>
                                        Ingresa cantidades arriba para ver el resumen de la devolución.
                                    </div>
                                ');
                            }

                            $rows       = '';
                            $grandTotal = 0.0;
                            $lineCount  = 0;

                            foreach ($selectedLines as $line) {
                                $lineCount++;
                                $unitSale    = strtoupper($line['unit_sale']   ?? 'UN');
                                $unitPrice   = (float) ($line['unit_price']    ?? 0);
                                $pricePerBox = (float) ($line['price_per_box'] ?? 0);
                                $convFactor  = max(1, (int) ($line['conversion_factor'] ?? 1));
                                $boxes       = (float) ($line['quantity_box']  ?? 0);
                                $units       = (float) ($line['quantity']      ?? 0);
                                $lineTotal   = (float) ($line['line_total']    ?? 0);
                                $description = htmlspecialchars($line['product_description'] ?? '—');
                                $grandTotal += $lineTotal;

                                $isBonif = ($unitPrice == 0 && $pricePerBox == 0);

                                // Armar etiqueta de cantidad
                                if ($boxes > 0 && $units > 0) {
                                    $qtyBadge = number_format($boxes, 0) . ' caj. + ' . number_format($units, 0) . ' und.';
                                    $priceStr = $isBonif ? '' : '× L.' . number_format($unitPrice, 2) . '/und.';
                                } elseif ($boxes > 0) {
                                    $qtyBadge = number_format($boxes, 0) . ' caja' . ($boxes != 1 ? 's' : '');
                                    $priceStr = $isBonif ? '' : '× L.' . number_format($pricePerBox, 2) . '/caja';
                                } else {
                                    $qtyBadge = number_format($units, 0) . ' und.';
                                    $priceStr = $isBonif ? '' : '× L.' . number_format($unitPrice, 2) . '/und.';
                                }

                                $bgRow    = ($lineCount % 2 === 0) ? '#f9fafb' : '#ffffff';
                                $totalStr = $isBonif
                                    ? '<span style="font-size:11px;background:#e5e7eb;color:#6b7280;padding:2px 6px;border-radius:4px;">Bonificación</span>'
                                    : '<strong style="color:#111827;">L.' . number_format($lineTotal, 2) . '</strong>';

                                $rows .= "
                                    <tr style=\"background:{$bgRow};\">
                                        <td style=\"padding:9px 12px;font-size:13px;color:#374151;font-weight:500;border-bottom:1px solid #f3f4f6;\">{$description}</td>
                                        <td style=\"padding:9px 12px;font-size:13px;white-space:nowrap;border-bottom:1px solid #f3f4f6;\">
                                            <span style=\"display:inline-block;background:#e0f2fe;color:#0369a1;font-size:12px;font-weight:600;padding:2px 7px;border-radius:12px;margin-right:6px;\">{$qtyBadge}</span>
                                            <span style=\"color:#9ca3af;font-size:12px;\">{$priceStr}</span>
                                        </td>
                                        <td style=\"padding:9px 12px;text-align:right;border-bottom:1px solid #f3f4f6;\">{$totalStr}</td>
                                    </tr>
                                ";
                            }

                            $grandTotalFmt = 'L.' . number_format($grandTotal, 2);
                            $itemLabel     = $lineCount === 1 ? '1 producto' : "{$lineCount} productos";

                            return new HtmlString("
                                <div style=\"border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-top:4px;\">
                                    <div style=\"background:linear-gradient(135deg,#1e3a5f 0%,#1e40af 100%);padding:10px 16px;display:flex;align-items:center;justify-content:space-between;\">
                                        <div style=\"display:flex;align-items:center;gap:8px;\">
                                            <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' viewBox='0 0 24 24' stroke='white' style='opacity:.9'>
                                                <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'/>
                                            </svg>
                                            <span style=\"color:white;font-size:13px;font-weight:600;letter-spacing:.3px;\">RESUMEN DE DEVOLUCIÓN</span>
                                        </div>
                                        <span style=\"background:rgba(255,255,255,.2);color:white;font-size:11px;padding:2px 8px;border-radius:10px;\">{$itemLabel}</span>
                                    </div>
                                    <table style=\"width:100%;border-collapse:collapse;background:white;\">
                                        <thead>
                                            <tr style=\"background:#f8fafc;\">
                                                <th style=\"padding:7px 12px;text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e5e7eb;\">Producto</th>
                                                <th style=\"padding:7px 12px;text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e5e7eb;\">Cantidad</th>
                                                <th style=\"padding:7px 12px;text-align:right;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e5e7eb;\">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>{$rows}</tbody>
                                    </table>
                                    <div style=\"background:#f8fafc;border-top:2px solid #e5e7eb;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;\">
                                        <span style=\"font-size:13px;color:#374151;font-weight:500;\">Total a devolver</span>
                                        <span style=\"font-size:18px;font-weight:700;color:#dc2626;background:#fef2f2;padding:4px 14px;border-radius:8px;border:1px solid #fecaca;\">{$grandTotalFmt}</span>
                                    </div>
                                </div>
                            ");
                        }),
                ])
                ->hidden(fn(Get $get) => !$get('invoice_id')),
        ]);
    }
}
