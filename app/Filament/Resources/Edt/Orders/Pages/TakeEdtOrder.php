<?php

namespace App\Filament\Resources\Edt\Orders\Pages;

use App\Enums\Edt\EdtOrderStatus;
use App\Enums\Edt\EdtPaymentType;
use App\Filament\Resources\Edt\Orders\EdtOrderResource;
use App\Models\Edt\EdtOrder;
use App\Models\Edt\EdtOrderLine;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtOrderService;
use App\Services\Edt\EdtPricingService;
use App\Services\Edt\EdtRuleException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Auth;

/**
 * Paso 2 de la preventa (celular): armar el pedido.
 *
 * Cada toque en − / + o cada cantidad escrita se guarda en el servidor al
 * momento (EdtOrderService::setQuantity), así una caída de señal no pierde
 * lo que ya se puso. Los precios y el total los calcula el servidor.
 *
 * Sirve para el borrador y para corregir una preventa ENVIADA mientras la
 * bodega no la toque (EdtOrderPolicy::update). Ya enviada, los precios son
 * los del día de la preventa (los de la línea), no los del catálogo.
 *
 * @property EdtOrder $record
 */
class TakeEdtOrder extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EdtOrderResource::class;

    protected string $view = 'filament.edt.orders.take';

    /** Productos por búsqueda: con el buscador se llega a cualquiera. */
    private const RESULTS_LIMIT = 40;

    public string $search = '';

    public bool $onlyInOrder = false;

    public ?string $note = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        if (! $this->authorizeEdit()) {
            return;
        }

        $this->note = $this->record->note;
        $this->onlyInOrder = $this->record->status !== EdtOrderStatus::Borrador;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->number ?? 'Nueva preventa';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    // ── Acciones de la pantalla ─────────────────────────────────────

    public function setQuantity(int $productId, mixed $boxes, mixed $units): void
    {
        $product = EdtProduct::query()->findOrFail($productId);

        $this->run(fn (EdtOrderService $orders): EdtOrder => $orders->setQuantity(
            $this->record,
            $product,
            max(0, (int) $boxes),
            max(0, (int) $units),
        ));
    }

    /**
     * − / + de cajas o sueltas.
     */
    public function step(int $productId, string $field, int $delta): void
    {
        /** @var EdtOrderLine|null $line */
        $line = $this->record->lines()->where('product_id', $productId)->first();
        $boxes = $line?->requested_boxes ?? 0;
        $units = $line?->requested_units ?? 0;

        if ($field === 'units') {
            $units = max(0, $units + $delta);
        } else {
            $boxes = max(0, $boxes + $delta);
        }

        $this->setQuantity($productId, $boxes, $units);
    }

    public function setPayment(string $paymentType): void
    {
        $this->run(fn (EdtOrderService $orders): EdtOrder => $orders->updateHeader(
            $this->record,
            EdtPaymentType::from($paymentType),
            $this->note,
        ));
    }

    public function updatedNote(): void
    {
        $this->run(fn (EdtOrderService $orders): EdtOrder => $orders->updateHeader(
            $this->record,
            $this->record->payment_type,
            $this->note,
        ));
    }

    public function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Enviar preventa')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->size('lg')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading('¿Enviar la preventa a la bodega?')
            ->modalDescription(fn (): string => $this->record->lines_count.' productos · L '.number_format((float) $this->record->total, 2)
                .'. La puedes corregir mientras la bodega no la revise.')
            ->modalSubmitActionLabel('Enviar')
            ->disabled(fn (): bool => $this->record->lines_count === 0)
            ->action(function (): void {
                $order = $this->run(fn (EdtOrderService $orders): EdtOrder => $orders->submit($this->record));

                if ($order?->status === EdtOrderStatus::Enviado) {
                    Notification::make()->title("Preventa {$order->number} enviada")->success()->send();
                    $this->redirect(EdtOrderResource::getUrl('index'));
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label(fn (): string => $this->record->status === EdtOrderStatus::Borrador ? 'Descartar' : 'Anular')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->outlined()
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->record->status === EdtOrderStatus::Borrador
                    ? '¿Descartar este borrador?'
                    : "¿Anular la preventa {$this->record->number}?")
                ->schema(fn (): array => $this->record->status === EdtOrderStatus::Borrador ? [] : [
                    TextInput::make('reason')->label('Motivo')->maxLength(255)->required(),
                ])
                ->visible(fn (): bool => Auth::user()?->can('cancel', $this->record) ?? false)
                ->action(function (array $data): void {
                    try {
                        $result = app(EdtOrderService::class)->cancel($this->record, $data['reason'] ?? null);
                    } catch (EdtRuleException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title($result === null ? 'Borrador descartado' : "Preventa {$result->number} anulada")->success()->send();
                    $this->redirect(EdtOrderResource::getUrl('index'));
                }),
        ];
    }

    // ── Datos de la vista ───────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->record->loadMissing(['client:id,code,name,business_name,municipality_id,credit_enabled,credit_limit,credit_days', 'client.municipality:id,name']);

        $lines = $this->record->lines()->get()->keyBy('product_id');

        return [
            'order' => $this->record,
            'products' => $this->productCards($lines),
            'isDraft' => $this->record->status === EdtOrderStatus::Borrador,
        ];
    }

    /**
     * Tarjetas de producto: primero lo que ya lleva el pedido, luego el
     * catálogo (una consulta, con límite). Ya enviada, el precio que se
     * muestra es el copiado en la línea.
     *
     * @param  \Illuminate\Support\Collection<int, EdtOrderLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function productCards($lines): array
    {
        $search = trim($this->search);
        $orderId = $this->record->id;

        $products = EdtProduct::query()
            ->select(['edt_products.id', 'edt_products.code', 'edt_products.description', 'edt_products.units_per_box', 'edt_products.isv_pct', 'edt_products.list_price', 'edt_products.box_cost', 'edt_products.supplier_id', 'edt_products.is_active'])
            ->leftJoin('edt_order_lines as line', fn (JoinClause $join) => $join
                ->on('line.product_id', '=', 'edt_products.id')
                ->where('line.order_id', '=', $orderId))
            ->where(fn (Builder $query) => $query->where('edt_products.is_active', true)->orWhereNotNull('line.id'))
            ->when($this->onlyInOrder, fn (Builder $query) => $query->whereNotNull('line.id'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('edt_products.code', 'ilike', "%{$search}%")
                ->orWhere('edt_products.description', 'ilike', "%{$search}%")))
            ->orderByRaw('line.id IS NULL')
            ->orderBy('edt_products.description')
            ->limit(self::RESULTS_LIMIT)
            ->get();

        $pricing = app(EdtPricingService::class);
        $frozen = $this->record->status !== EdtOrderStatus::Borrador;

        return $products->map(function (EdtProduct $product) use ($lines, $pricing, $frozen): array {
            /** @var EdtOrderLine|null $line */
            $line = $lines->get($product->id);

            $quote = $line && $frozen
                ? $pricing->quote((string) $line->list_price, $line->units_per_box, (string) $line->isv_pct, (string) $line->box_cost, $pricing->activeTiers())
                : $product->priceQuote();

            return [
                'id' => $product->id,
                'code' => $product->code,
                'description' => $product->description,
                'unitsPerBox' => (int) ($line->units_per_box ?? $product->units_per_box),
                'isv' => (int) ($line->isv_pct ?? $product->isv_pct),
                'boxPrice' => $quote->retailBoxPrice,
                'unitPrice' => $quote->retailUnitPrice,
                'tiers' => array_map(fn (array $tier): array => ['min' => $tier['min_boxes'], 'price' => $tier['box_price']], $quote->wholesale),
                'boxes' => $line->requested_boxes ?? 0,
                'units' => $line->requested_units ?? 0,
                'tierName' => $line?->tier_name,
                'lineTotal' => $line?->total,
            ];
        })->all();
    }

    // ── Internos ────────────────────────────────────────────────────

    /**
     * Corre una operación del servicio; si una regla lo impide, avisa y
     * deja el pedido como estaba.
     *
     * @param  Closure(EdtOrderService): EdtOrder  $operation
     */
    private function run(Closure $operation): ?EdtOrder
    {
        $this->record->refresh();

        if (! $this->authorizeEdit()) {
            return null;
        }

        try {
            $order = $operation(app(EdtOrderService::class));
        } catch (EdtRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->record->refresh();

            return null;
        }

        $this->record = $order->fresh();

        return $this->record;
    }

    /**
     * Si ya no se puede cambiar (la bodega la empezó a revisar), avisa y
     * manda a verla. Si ni siquiera la puede ver, 403.
     */
    private function authorizeEdit(): bool
    {
        if (Auth::user()?->can('update', $this->record)) {
            return true;
        }

        abort_unless(Auth::user()?->can('view', $this->record), 403);

        Notification::make()->title('Esta preventa ya no se puede cambiar.')->warning()->send();
        $this->redirect(EdtOrderResource::getUrl('view', ['record' => $this->record]));

        return false;
    }
}
