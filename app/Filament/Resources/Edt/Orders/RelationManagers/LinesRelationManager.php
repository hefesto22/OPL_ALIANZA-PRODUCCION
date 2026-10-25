<?php

namespace App\Filament\Resources\Edt\Orders\RelationManagers;

use App\Models\Edt\EdtOrder;
use App\Models\Edt\EdtOrderLine;
use App\Services\Edt\EdtOrderService;
use App\Services\Edt\EdtRuleException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Líneas de la preventa: lo que pidió el vendedor y lo que queda. La
 * bodega baja aquí lo que no hay («Ajustar» o «No hay»); nunca sube.
 * Las líneas no tienen Policy propia: quien ve la preventa ve sus líneas
 * y quien la puede revisar las ajusta (EdtOrderPolicy::review).
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Productos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view', $ownerRecord) ?? false;
    }

    #[On('edt-order-updated')]
    public function refreshLines(): void
    {
        // Basta con que Livewire vuelva a renderizar el componente.
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('description')
            ->paginated(false)
            ->columns([
                TextColumn::make('description')
                    ->label('Producto')
                    ->description(fn (EdtOrderLine $record): string => $record->product_code.' · '.$record->units_per_box.' u. por caja')
                    ->wrap(),

                TextColumn::make('requested')
                    ->label('Pidió')
                    ->state(fn (EdtOrderLine $record): string => self::quantity($record->requested_boxes, $record->requested_units))
                    ->alignEnd(),

                TextColumn::make('approved')
                    ->label('Queda')
                    ->state(fn (EdtOrderLine $record): string => self::quantity($record->boxes, $record->units))
                    ->badge()
                    ->color(fn (EdtOrderLine $record): string => match (true) {
                        $record->totalUnits() === 0 => 'danger',
                        $record->isReduced() => 'warning',
                        default => 'success',
                    })
                    ->alignEnd(),

                TextColumn::make('box_price')
                    ->label('Precio caja')
                    ->money('HNL')
                    ->description(fn (EdtOrderLine $record): string => ($record->tier_name ?? 'DETALLE').' · unidad L '.number_format((float) $record->unit_price, 2))
                    ->alignEnd(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold')
                    ->description(fn (EdtOrderLine $record): ?string => $record->isReduced()
                        ? 'Pidió L '.number_format((float) $record->requested_total, 2)
                        : null)
                    ->alignEnd(),
            ])
            ->recordActions([
                Action::make('adjust')
                    ->label('Ajustar')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->modalHeading(fn (EdtOrderLine $record): string => $record->description)
                    ->modalDescription(fn (EdtOrderLine $record): string => 'Pidió '.self::quantity($record->requested_boxes, $record->requested_units).'. Pon lo que sí hay; no puede ser más.')
                    ->fillForm(fn (EdtOrderLine $record): array => ['boxes' => $record->boxes, 'units' => $record->units])
                    ->schema(fn (EdtOrderLine $record): array => array_values(array_filter([
                        TextInput::make('boxes')->label('Cajas')->integer()->minValue(0)->required(),
                        $record->units_per_box > 1
                            ? TextInput::make('units')->label('Sueltas')->integer()->minValue(0)->default(0)
                            : null,
                    ])))
                    ->modalWidth('sm')
                    ->visible(fn (): bool => $this->canReview())
                    ->action(fn (EdtOrderLine $record, array $data) => $this->adjust($record, (int) $data['boxes'], (int) ($data['units'] ?? 0))),

                Action::make('none')
                    ->label('No hay')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (EdtOrderLine $record): string => "¿No hay {$record->description}?")
                    ->modalDescription('La línea queda en 0 y no se factura.')
                    ->visible(fn (EdtOrderLine $record): bool => $this->canReview() && $record->totalUnits() > 0)
                    ->action(fn (EdtOrderLine $record) => $this->adjust($record, 0, 0)),
            ]);
    }

    private function canReview(): bool
    {
        /** @var EdtOrder $order */
        $order = $this->getOwnerRecord();

        return Auth::user()?->can('review', $order) ?? false;
    }

    private function adjust(EdtOrderLine $line, int $boxes, int $units): void
    {
        try {
            app(EdtOrderService::class)->adjustLine($line, $boxes, $units);
        } catch (EdtRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->getOwnerRecord()->refresh();
        Notification::make()->title('Línea ajustada')->success()->send();
        $this->dispatch('edt-order-updated');
    }

    private static function quantity(int $boxes, int $units): string
    {
        $parts = array_filter([
            $boxes > 0 ? "{$boxes} CJ" : null,
            $units > 0 ? "{$units} UN" : null,
        ]);

        return $parts === [] ? '0' : implode(' + ', $parts);
    }
}
