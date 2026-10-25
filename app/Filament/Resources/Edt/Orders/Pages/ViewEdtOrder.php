<?php

namespace App\Filament\Resources\Edt\Orders\Pages;

use App\Filament\Resources\Edt\Orders\EdtOrderResource;
use App\Models\Edt\EdtOrder;
use App\Services\Edt\EdtOrderService;
use App\Services\Edt\EdtRuleException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Una preventa. La bodega la revisa aquí: baja lo que no hay en las líneas
 * (LinesRelationManager) y la aprueba o rechaza. El vendedor la ve, la
 * corrige (TakeEdtOrder) o la anula mientras la bodega no la toque.
 *
 * @property EdtOrder $record
 */
class ViewEdtOrder extends ViewRecord
{
    protected static string $resource = EdtOrderResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->number ?? 'Borrador';
    }

    /**
     * Las líneas avisan cuando la bodega ajusta una, para que los totales
     * y el estado del encabezado se actualicen sin recargar.
     */
    #[On('edt-order-updated')]
    public function refreshOrder(): void
    {
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Aprobar')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "¿Aprobar la preventa {$this->record->number}?")
                ->modalDescription(fn (): string => $this->record->lines_count.' productos · L '.number_format((float) $this->record->total, 2)
                    .'. Queda lista para facturar.')
                ->modalSubmitActionLabel('Aprobar')
                ->visible(fn (): bool => $this->can('review'))
                ->action(fn () => $this->run(fn (EdtOrderService $orders): EdtOrder => $orders->approve($this->record), 'Preventa aprobada')),

            Action::make('reject')
                ->label('Rechazar')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->outlined()
                ->modalHeading(fn (): string => "Rechazar la preventa {$this->record->number}")
                ->schema([
                    TextInput::make('reason')->label('Motivo')->required()->maxLength(255),
                ])
                ->modalSubmitActionLabel('Rechazar')
                ->visible(fn (): bool => $this->can('review'))
                ->action(fn (array $data) => $this->run(fn (EdtOrderService $orders): EdtOrder => $orders->reject($this->record, $data['reason']), 'Preventa rechazada')),

            Action::make('edit')
                ->label('Corregir')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (): string => EdtOrderResource::getUrl('take', ['record' => $this->record]))
                ->visible(fn (): bool => $this->can('update')),

            Action::make('cancel')
                ->label('Anular')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->outlined()
                ->modalHeading(fn (): string => "¿Anular la preventa {$this->record->number}?")
                ->schema([
                    TextInput::make('reason')->label('Motivo')->required()->maxLength(255),
                ])
                ->modalSubmitActionLabel('Anular')
                ->visible(fn (): bool => $this->can('cancel') && $this->record->number !== null)
                ->action(fn (array $data) => $this->run(fn (EdtOrderService $orders): ?EdtOrder => $orders->cancel($this->record, $data['reason']), 'Preventa anulada')),
        ];
    }

    private function can(string $ability): bool
    {
        return Auth::user()?->can($ability, $this->record) ?? false;
    }

    /**
     * @param  Closure(EdtOrderService): ?EdtOrder  $operation
     */
    private function run(Closure $operation, string $success): void
    {
        try {
            $operation(app(EdtOrderService::class));
        } catch (EdtRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } finally {
            $this->record->refresh();
        }

        Notification::make()->title($success)->success()->send();
        $this->dispatch('edt-order-updated');
    }
}
