<?php

namespace App\Filament\Resources\Manifests\Actions;

use App\Models\Invoice;
use App\Models\ReturnReason;
use App\Services\ReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                if (!in_array($record->status, ['imported', 'partial_return'])) {
                    return false;
                }

                return app(ReturnService::class)->hasAvailableLines($record);
            })
            ->mountUsing(function ($form, Invoice $record): void {
                $record->load('lines');
                $returnService = app(ReturnService::class);

                $lineIds        = $record->lines->pluck('id')->toArray();
                $returnedByLine = $returnService->getReturnedQuantitiesForLines($lineIds);

                $lines = $record->lines->map(function ($line) use ($returnedByLine): array {
                    $alreadyReturned = (float)($returnedByLine[$line->id] ?? 0);
                    $available       = max(0, (float) $line->quantity_fractions - $alreadyReturned);
                    $convFactor      = max(1, (int) ($line->conversion_factor ?? 1));
                    $unitSale        = strtoupper($line->unit_sale ?? 'UN');
                    // Null → usar price; 0 → bonificación (gratis). No usar ?: porque 0 es falsy.
                    $unitPrice       = $line->price_min_sale !== null ? (float) $line->price_min_sale : (float) $line->price;

                    $availableBoxes = ($unitSale === 'CJ')
                        ? (int) floor($available / $convFactor)
                        : 0;

                    $pricePerBox = ($unitSale === 'CJ')
                        ? round($convFactor * $unitPrice, 2)
                        : 0.0;

                    return [
                        'invoice_line_id'     => $line->id,
                        'line_number'         => $line->line_number,
                        'product_id'          => $line->product_id,
                        'product_description' => $line->product_description,
                        'unit_sale'           => $unitSale,
                        'conversion_factor'   => $convFactor,
                        'quantity_box'        => 0,
                        'quantity'            => 0,
                        'available_quantity'  => $available,
                        'available_boxes'     => $availableBoxes,
                        'unit_price'          => $unitPrice,
                        'price_per_box'       => $pricePerBox,
                        'line_total'          => 0,
                    ];
                })->toArray();

                // Disponible = total factura − lo ya devuelto en otras devoluciones
                $alreadyReturnedTotal = $record->returns()
                    ->whereIn('status', ['approved', 'pending'])
                    ->sum('total');
                $availableTotal = max(0, round((float) $record->total - (float) $alreadyReturnedTotal, 2));

                $form->fill([
                    'return_date'      => now()->toDateString(),
                    'invoice_number'   => $record->invoice_number,
                    'client_name'      => $record->client_name,
                    'invoice_total'    => round((float) $record->total, 2),
                    'available_total'  => $availableTotal,
                    'lines'            => $lines,
                ]);
            })
            ->schema([
                // Campos ocultos globales del encabezado
                Hidden::make('invoice_number'),
                Hidden::make('client_name'),
                Hidden::make('invoice_total'),
                Hidden::make('available_total'),

                Section::make('')
                    ->schema([
                        // ── Cabecera: nombre factura + totales ──────────────
                        Placeholder::make('invoice_header')
                            ->label('')
                            ->content(function (Get $get): HtmlString {
                                $invoiceNumber  = htmlspecialchars($get('invoice_number') ?? '');
                                $clientName     = htmlspecialchars($get('client_name')    ?? '');
                                $invoiceTotal   = (float) ($get('invoice_total')          ?? 0);
                                $availableTotal = (float) ($get('available_total')        ?? 0);

                                $invoiceTotalFmt   = 'L.' . number_format($invoiceTotal,   2);
                                $availableTotalFmt = 'L.' . number_format($availableTotal, 2);

                                return new HtmlString("
                                    <div style=\"display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:2px 0 6px;\">
                                        <div style=\"display:flex;align-items:center;gap:8px;\">
                                            <svg xmlns='http://www.w3.org/2000/svg' width='18' height='18' fill='none' viewBox='0 0 24 24' stroke='#6b7280'>
                                                <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'/>
                                            </svg>
                                            <div>
                                                <div style=\"font-size:15px;font-weight:700;color:#111827;\">Factura #{$invoiceNumber}</div>
                                                <div style=\"font-size:12px;color:#6b7280;margin-top:1px;\">{$clientName}</div>
                                            </div>
                                        </div>
                                        <div style=\"display:flex;gap:10px;flex-wrap:wrap;\">
                                            <div style=\"
                                                background:#f0fdf4;
                                                border:1px solid #bbf7d0;
                                                border-radius:8px;
                                                padding:6px 14px;
                                                text-align:center;
                                                min-width:130px;
                                            \">
                                                <div style=\"font-size:10px;font-weight:600;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;\">Total Factura</div>
                                                <div style=\"font-size:16px;font-weight:700;color:#15803d;\">{$invoiceTotalFmt}</div>
                                            </div>
                                            <div style=\"
                                                background:#eff6ff;
                                                border:1px solid #bfdbfe;
                                                border-radius:8px;
                                                padding:6px 14px;
                                                text-align:center;
                                                min-width:130px;
                                            \">
                                                <div style=\"font-size:10px;font-weight:600;color:#2563eb;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;\">Disponible p/Dev.</div>
                                                <div style=\"font-size:16px;font-weight:700;color:#1d4ed8;\">{$availableTotalFmt}</div>
                                            </div>
                                        </div>
                                    </div>
                                ");
                            }),

                        Grid::make(2)->schema([
                            Select::make('return_reason_id')
                                ->label('Motivo de Devolución')
                                ->required()
                                ->options(
                                    ReturnReason::where('is_active', true)
                                        ->orderBy('code')
                                        ->pluck('description', 'id')
                                )
                                ->searchable(),

                            DatePicker::make('return_date')
                                ->label('Fecha de Devolución')
                                ->required()
                                ->default(today())
                                ->maxDate(today()),
                        ]),
                    ]),

                Section::make('Productos a Devolver')
                    ->headerActions([
                        // ── Devolver Todo ─────────────────────────────────
                        Action::make('devolver_todo')
                            ->label('Devolver Todo')
                            ->icon('heroicon-o-arrow-uturn-left')
                            ->color('danger')
                            ->action(function (Get $get, Set $set): void {
                                $lines = $get('lines');
                                foreach ($lines as $key => $line) {
                                    $unitSale   = strtoupper($line['unit_sale'] ?? 'UN');
                                    $convFactor = max(1, (int) ($line['conversion_factor'] ?? 1));
                                    $price      = (float) ($line['unit_price'] ?? 0);

                                    if ($unitSale === 'CJ') {
                                        $maxBoxes                      = (int) ($line['available_boxes'] ?? 0);
                                        $lines[$key]['quantity_box']   = $maxBoxes;
                                        $lines[$key]['quantity']       = 0;
                                        $lines[$key]['line_total']     = round($maxBoxes * $convFactor * $price, 2);
                                    } else {
                                        $maxUnits                      = (float) ($line['available_quantity'] ?? 0);
                                        $lines[$key]['quantity']       = $maxUnits;
                                        $lines[$key]['quantity_box']   = 0;
                                        $lines[$key]['line_total']     = round($maxUnits * $price, 2);
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
                                    $lines[$key]['quantity']     = 0;
                                    $lines[$key]['quantity_box'] = 0;
                                    $lines[$key]['line_total']   = 0;
                                }
                                $set('lines', $lines);
                            }),
                    ])
                    ->schema([
                        Repeater::make('lines')
                            ->label('')
                            ->schema([
                                // ── Campos ocultos (referencia server-side) ────
                                Hidden::make('invoice_line_id'),
                                Hidden::make('available_quantity'),
                                Hidden::make('available_boxes'),
                                Hidden::make('conversion_factor'),
                                Hidden::make('unit_price'),
                                Hidden::make('price_per_box'),

                                // ── Fila única: identificación + cantidad + total ──
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

                                    TextInput::make('unit_sale')
                                        ->label('Unidad')
                                        ->disabled()->dehydrated(true)
                                        ->columnSpan(1),

                                    // ── Cajas (solo CJ) ───────────────────────
                                    TextInput::make('quantity_box')
                                        ->label('Cajas a Dev.')
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->live(debounce: 500)
                                        ->helperText(function (Get $get): string {
                                            $pricePerBox = (float) ($get('price_per_box') ?? 0);
                                            $maxBoxes    = (int) ($get('available_boxes') ?? 0);
                                            if ($pricePerBox > 0) {
                                                return 'L.' . number_format($pricePerBox, 2) . ' / caja  ·  máx: ' . $maxBoxes;
                                            }
                                            return 'Bonificación  ·  máx: ' . $maxBoxes;
                                        })
                                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                            $boxes      = max(0, (int) $state);
                                            $maxBoxes   = (int) ($get('available_boxes') ?? 0);
                                            $convFactor = max(1, (int) ($get('conversion_factor') ?? 1));
                                            $price      = (float) ($get('unit_price') ?? 0);

                                            if ($boxes > $maxBoxes) {
                                                $boxes = $maxBoxes;
                                                $set('quantity_box', $boxes);
                                            }

                                            // Para CJ: total = cajas × factor × precio_unitario
                                            $set('line_total', round($boxes * $convFactor * $price, 2));
                                        })
                                        ->hidden(fn (Get $get) => ($get('unit_sale') ?? 'UN') !== 'CJ')
                                        ->columnSpan(2),

                                    // ── Unidades (solo UN) ────────────────────
                                    TextInput::make('quantity')
                                        ->label('Cant. a Dev.')
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->live(debounce: 500)
                                        ->helperText(function (Get $get): string {
                                            $unitPrice = (float) ($get('unit_price') ?? 0);
                                            $maxQty    = (float) ($get('available_quantity') ?? 0);
                                            if ($unitPrice > 0) {
                                                return 'L.' . number_format($unitPrice, 2) . ' / un.  ·  máx: ' . number_format($maxQty, 0);
                                            }
                                            return 'Bonificación  ·  máx: ' . number_format($maxQty, 0);
                                        })
                                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                            $qty    = max(0, (float) $state);
                                            $maxQty = (float) ($get('available_quantity') ?? 0);
                                            $price  = (float) ($get('unit_price') ?? 0);

                                            if ($qty > $maxQty) {
                                                $qty = $maxQty;
                                                $set('quantity', $qty);
                                            }

                                            $set('line_total', round($qty * $price, 2));
                                        })
                                        ->hidden(fn (Get $get) => ($get('unit_sale') ?? 'UN') === 'CJ')
                                        ->columnSpan(2),

                                    TextInput::make('line_total')
                                        ->label('Total a Dev.')
                                        ->prefix('L.')
                                        ->disabled()->dehydrated(true)
                                        ->columnSpan(2),
                                ]),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),

                        // ── Resumen reactivo (recibo) ──────────────────────────
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
                                    $unitSale    = strtoupper($line['unit_sale'] ?? 'UN');
                                    $unitPrice   = (float) ($line['unit_price']   ?? 0);
                                    $pricePerBox = (float) ($line['price_per_box'] ?? 0);
                                    $boxes       = (float) ($line['quantity_box'] ?? 0);
                                    $units       = (float) ($line['quantity']     ?? 0);
                                    $lineTotal   = (float) ($line['line_total']   ?? 0);
                                    $description = htmlspecialchars($line['product_description'] ?? '—');
                                    $grandTotal += $lineTotal;

                                    $isBonif = ($lineTotal == 0 && ($unitPrice == 0 && $pricePerBox == 0));

                                    if ($unitSale === 'CJ') {
                                        $qtyBadge = number_format($boxes, 0) . ' caja' . ($boxes != 1 ? 's' : '');
                                        $priceStr = $isBonif ? '' : '× L.' . number_format($pricePerBox, 2) . '/caja';
                                    } else {
                                        $qtyBadge = number_format($units, 0) . ' und.';
                                        $priceStr = $isBonif ? '' : '× L.' . number_format($unitPrice, 2) . '/und.';
                                    }

                                    $bgRow      = ($lineCount % 2 === 0) ? '#f9fafb' : '#ffffff';
                                    $totalColor = $isBonif ? '#9ca3af' : '#111827';
                                    $totalStr   = $isBonif
                                        ? '<span style="font-size:11px;background:#e5e7eb;color:#6b7280;padding:2px 6px;border-radius:4px;">Bonificación</span>'
                                        : '<strong style="color:' . $totalColor . '">L.' . number_format($lineTotal, 2) . '</strong>';

                                    $rows .= "
                                        <tr style=\"background:{$bgRow};\">
                                            <td style=\"padding:9px 12px; font-size:13px; color:#374151; font-weight:500; border-bottom:1px solid #f3f4f6;\">
                                                {$description}
                                            </td>
                                            <td style=\"padding:9px 12px; font-size:13px; color:#6b7280; white-space:nowrap; border-bottom:1px solid #f3f4f6;\">
                                                <span style=\"display:inline-block;background:#e0f2fe;color:#0369a1;font-size:12px;font-weight:600;padding:2px 7px;border-radius:12px;margin-right:6px;\">{$qtyBadge}</span>
                                                <span style=\"color:#9ca3af;font-size:12px;\">{$priceStr}</span>
                                            </td>
                                            <td style=\"padding:9px 12px; text-align:right; border-bottom:1px solid #f3f4f6;\">
                                                {$totalStr}
                                            </td>
                                        </tr>
                                    ";
                                }

                                $grandTotalFmt = 'L.' . number_format($grandTotal, 2);
                                $itemLabel     = $lineCount === 1 ? '1 producto' : "{$lineCount} productos";

                                return new HtmlString("
                                    <div style=\"
                                        border-radius:10px;
                                        overflow:hidden;
                                        border:1px solid #e5e7eb;
                                        box-shadow:0 1px 3px rgba(0,0,0,.06);
                                        margin-top:4px;
                                    \">
                                        <!-- Cabecera -->
                                        <div style=\"
                                            background:linear-gradient(135deg,#1e3a5f 0%,#1e40af 100%);
                                            padding:10px 16px;
                                            display:flex;
                                            align-items:center;
                                            justify-content:space-between;
                                        \">
                                            <div style=\"display:flex;align-items:center;gap:8px;\">
                                                <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' viewBox='0 0 24 24' stroke='white' style='opacity:.9'>
                                                    <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'/>
                                                </svg>
                                                <span style=\"color:white;font-size:13px;font-weight:600;letter-spacing:.3px;\">RESUMEN DE DEVOLUCIÓN</span>
                                            </div>
                                            <span style=\"background:rgba(255,255,255,.2);color:white;font-size:11px;padding:2px 8px;border-radius:10px;\">{$itemLabel}</span>
                                        </div>

                                        <!-- Tabla -->
                                        <table style=\"width:100%;border-collapse:collapse;background:white;\">
                                            <thead>
                                                <tr style=\"background:#f8fafc;\">
                                                    <th style=\"padding:7px 12px;text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e5e7eb;\">Producto</th>
                                                    <th style=\"padding:7px 12px;text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e5e7eb;\">Cantidad</th>
                                                    <th style=\"padding:7px 12px;text-align:right;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e5e7eb;\">Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {$rows}
                                            </tbody>
                                        </table>

                                        <!-- Total footer -->
                                        <div style=\"
                                            background:#f8fafc;
                                            border-top:2px solid #e5e7eb;
                                            padding:12px 16px;
                                            display:flex;
                                            justify-content:space-between;
                                            align-items:center;
                                        \">
                                            <span style=\"font-size:13px;color:#374151;font-weight:500;\">Total a devolver</span>
                                            <span style=\"
                                                font-size:18px;
                                                font-weight:700;
                                                color:#dc2626;
                                                background:#fef2f2;
                                                padding:4px 14px;
                                                border-radius:8px;
                                                border:1px solid #fecaca;
                                            \">{$grandTotalFmt}</span>
                                        </div>
                                    </div>
                                ");
                            }),
                    ]),
            ])
            ->action(function (array $data, Invoice $record): void {
                $hasLines = collect($data['lines'] ?? [])
                    ->filter(fn ($l) =>
                        (float) ($l['quantity_box'] ?? 0) > 0 ||
                        (float) ($l['quantity']     ?? 0) > 0
                    )
                    ->isNotEmpty();

                if (!$hasLines) {
                    Notification::make()
                        ->title('Debes ingresar al menos un producto a devolver.')
                        ->warning()->send();

                    return;
                }

                try {
                    app(ReturnService::class)->createReturn([
                        'invoice_id'       => $record->id,
                        'return_reason_id' => $data['return_reason_id'],
                        'return_date'      => $data['return_date'],
                        'lines'            => $data['lines'],
                        'created_by'       => Auth::id(),
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
            ->modalWidth('7xl')
            ->modalSubmitActionLabel('Registrar Devolución');
    }
}
