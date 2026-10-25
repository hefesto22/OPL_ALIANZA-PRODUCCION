<?php

namespace App\Filament\Resources\Edt\Orders\Schemas;

use App\Models\Edt\EdtOrder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Encabezado y totales de una preventa (solo lectura). Las líneas van en
 * LinesRelationManager, debajo.
 */
class EdtOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    Section::make('Preventa')
                        ->columnSpan(['lg' => 2])
                        ->columns(['default' => 2, 'md' => 3])
                        ->schema([
                            TextEntry::make('client.name')
                                ->label('Cliente')
                                ->weight('bold')
                                ->helperText(fn (EdtOrder $record): string => trim($record->client->code.' · '.$record->client->municipality?->name, ' ·'))
                                ->columnSpan(['default' => 2, 'md' => 2]),
                            TextEntry::make('status')->label('Estado')->badge(),
                            TextEntry::make('order_date')->label('Fecha')->date('d/m/Y'),
                            TextEntry::make('seller.name')->label('Vendedor'),
                            TextEntry::make('warehouse.code')->label('Bodega')->badge()->color('gray'),
                            TextEntry::make('payment_type')->label('Pago'),
                            TextEntry::make('route.name')->label('Ruta')->placeholder('—'),
                            TextEntry::make('submitted_at')->label('Enviada')->dateTime('d/m/Y h:i a')->placeholder('—'),
                            TextEntry::make('note')->label('Nota')->placeholder('—')->columnSpanFull(),
                            TextEntry::make('reviewer.name')
                                ->label('Revisó')
                                ->helperText(fn (EdtOrder $record): ?string => $record->reviewed_at?->format('d/m/Y h:i a'))
                                ->visible(fn (EdtOrder $record): bool => $record->reviewed_by !== null),
                            TextEntry::make('close_reason')
                                ->label('Motivo')
                                ->color('danger')
                                ->visible(fn (EdtOrder $record): bool => filled($record->close_reason))
                                ->columnSpan(['default' => 2, 'md' => 2]),
                        ]),

                    Section::make('Totales')
                        ->description('Con el precio del día de la preventa.')
                        ->columns(2)
                        ->schema([
                            self::money('exempt_total', 'Exento'),
                            self::money('taxable_15', 'Gravado 15%'),
                            self::money('isv_15', 'ISV 15%'),
                            self::money('taxable_18', 'Gravado 18%'),
                            self::money('isv_18', 'ISV 18%'),
                            self::money('total', 'Total')
                                ->weight('bold')
                                ->size('lg')
                                ->helperText(fn (EdtOrder $record): ?string => $record->isReduced()
                                    ? 'El vendedor pidió L '.number_format((float) $record->requested_total, 2)
                                    : null),
                        ]),
                ]),
        ]);
    }

    private static function money(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)->label($label)->money('HNL');
    }
}
