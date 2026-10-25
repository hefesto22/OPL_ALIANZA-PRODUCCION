<?php

namespace App\Filament\Resources\Edt\Orders\Pages;

use App\Enums\Edt\EdtOrderStatus;
use App\Filament\Resources\Edt\Orders\EdtOrderResource;
use App\Models\Edt\EdtOrder;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Bandeja de preventas. La bodega arranca en «Por revisar»; el vendedor
 * ve sus borradores, enviadas y cerradas.
 */
class ListEdtOrders extends ListRecords
{
    protected static string $resource = EdtOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newOrder')
                ->label('Nueva preventa')
                ->icon(Heroicon::OutlinedPlus)
                ->url(EdtOrderResource::getUrl('pick'))
                ->visible(fn (): bool => Auth::user()?->can('create', EdtOrder::class) ?? false),
        ];
    }

    public function getTabs(): array
    {
        if ($this->isSeller()) {
            return [
                'todas' => Tab::make('Todas'),
                'borradores' => $this->tab('Borradores', [EdtOrderStatus::Borrador]),
                'enviadas' => $this->tab('Enviadas', [EdtOrderStatus::Enviado, EdtOrderStatus::EnRevision]),
                'cerradas' => $this->tab('Cerradas', [EdtOrderStatus::Aprobado, EdtOrderStatus::Rechazado, EdtOrderStatus::Anulado]),
            ];
        }

        $pending = [EdtOrderStatus::Enviado, EdtOrderStatus::EnRevision];

        return [
            'por_revisar' => $this->tab('Por revisar', $pending)
                ->badge(fn (): int => EdtOrderResource::getEloquentQuery()->whereIn('status', $pending)->count())
                ->badgeColor('warning'),
            'aprobadas' => $this->tab('Aprobadas', [EdtOrderStatus::Aprobado]),
            'cerradas' => $this->tab('Rechazadas y anuladas', [EdtOrderStatus::Rechazado, EdtOrderStatus::Anulado]),
            'todas' => Tab::make('Todas'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return $this->isSeller() ? 'todas' : 'por_revisar';
    }

    /**
     * @param  list<EdtOrderStatus>  $statuses
     */
    private function tab(string $label, array $statuses): Tab
    {
        return Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', $statuses));
    }

    private function isSeller(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->edtSeller !== null;
    }
}
