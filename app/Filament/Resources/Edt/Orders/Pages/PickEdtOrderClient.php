<?php

namespace App\Filament\Resources\Edt\Orders\Pages;

use App\Enums\Edt\EdtOrderStatus;
use App\Filament\Resources\Edt\Orders\EdtOrderResource;
use App\Models\Edt\EdtClient;
use App\Models\Edt\EdtOrder;
use App\Models\Edt\EdtSeller;
use App\Services\Edt\EdtOrderService;
use App\Services\Edt\EdtRuleException;
use App\Services\Edt\EdtSellerZones;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Paso 1 de la preventa (celular): elegir el cliente.
 *
 * Salen todos los clientes de las zonas del vendedor; primero los de la
 * ruta que visita hoy (marcados HOY) y los que ya tienen un borrador.
 * Máximo 50 por búsqueda: con el buscador se llega a cualquiera.
 */
class PickEdtOrderClient extends Page
{
    protected static string $resource = EdtOrderResource::class;

    protected string $view = 'filament.edt.orders.pick-client';

    protected static ?string $title = 'Nueva preventa';

    public string $search = '';

    public static function canAccess(array $parameters = []): bool
    {
        return Auth::user()?->can('create', EdtOrder::class) ?? false;
    }

    public function getSubheading(): ?string
    {
        return '¿A qué cliente le vas a tomar el pedido?';
    }

    public function start(int $clientId): void
    {
        $client = EdtClient::query()->findOrFail($clientId);

        try {
            $order = app(EdtOrderService::class)->draftFor($this->seller(), $client);
        } catch (EdtRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->redirect(EdtOrderResource::getUrl('take', ['record' => $order]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $seller = $this->seller();
        $today = EdtSellerZones::municipalityIds($seller, today(), onlyVisitDay: true);
        $search = trim($this->search);

        $clients = EdtSellerZones::applyToClients(EdtClient::query(), $seller)
            ->where('edt_clients.is_active', true)
            ->select(['edt_clients.id', 'edt_clients.code', 'edt_clients.name', 'edt_clients.business_name', 'edt_clients.municipality_id', 'edt_clients.credit_enabled'])
            ->selectRaw('edt_clients.municipality_id IN ('.$today->toSql().') AS is_today', $today->getBindings())
            ->with('municipality:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('edt_clients.name', 'ilike', "%{$search}%")
                ->orWhere('edt_clients.code', 'ilike', "%{$search}%")
                ->orWhere('edt_clients.business_name', 'ilike', "%{$search}%")))
            ->orderByDesc('is_today')
            ->orderBy('edt_clients.name')
            ->limit(50)
            ->get();

        $drafts = EdtOrder::query()
            ->where('seller_id', $seller->id)
            ->where('status', EdtOrderStatus::Borrador)
            ->pluck('lines_count', 'client_id');

        return [
            'clients' => $clients
                ->sortBy(fn (EdtClient $client): int => $drafts->has($client->id) ? 0 : 1)
                ->values(),
            'drafts' => $drafts,
        ];
    }

    private function seller(): EdtSeller
    {
        return Auth::user()->edtSeller;
    }
}
