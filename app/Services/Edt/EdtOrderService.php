<?php

namespace App\Services\Edt;

use App\Enums\Edt\EdtOrderStatus;
use App\Enums\Edt\EdtPaymentType;
use App\Models\Edt\EdtClient;
use App\Models\Edt\EdtOrder;
use App\Models\Edt\EdtOrderLine;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtRouteAssignment;
use App\Models\Edt\EdtSeller;
use App\Models\User;
use App\Support\Edt\EdtModule;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Preventas del EDT: todo lo que cambia un pedido pasa por aquí.
 *
 * Vendedor: draftFor() → setQuantity() / updateHeader() → submit(); puede
 * corregir o anular (cancel) mientras la bodega no la toque.
 * Bodega: adjustLine() (solo bajar, hasta 0) → approve() o reject().
 *
 * Reglas que valen en todos los métodos:
 *  - Cada cambio va en una transacción con el pedido bloqueado
 *    (lockForUpdate): el vendedor corrigiendo y la bodega revisando a la
 *    vez se atienden en fila, y el segundo ve el estado que dejó el primero.
 *  - Precios y totales los calcula el servidor (EdtPricingService), nunca
 *    lo que mande la pantalla.
 *  - Precio del día de la preventa: mientras es borrador, la línea toma el
 *    precio vigente del producto; al enviar se copia el del día y ya no
 *    cambia (correcciones y revisión recalculan sobre esa copia, porque al
 *    bajar cajas puede dejar de aplicar la escala mayorista).
 *  - Unidades sueltas: si llegan a una caja se pasan a cajas (24 sueltas
 *    de una caja de 24 = 1 caja). La escala solo cuenta cajas.
 *
 * Errores de negocio: EdtRuleException con el mensaje para el usuario.
 */
class EdtOrderService
{
    public function __construct(private readonly EdtPricingService $pricing) {}

    // ── Vendedor ────────────────────────────────────────────────────

    /**
     * El borrador del vendedor para ese cliente; lo crea si no hay. Un doble
     * toque no crea dos (índice único parcial edt_orders_one_draft_per_client).
     */
    public function draftFor(EdtSeller $seller, EdtClient $client): EdtOrder
    {
        if (! $seller->is_active) {
            throw new EdtRuleException('Tu ficha de vendedor está inactiva.');
        }

        $isMine = EdtSellerZones::applyToClients(EdtClient::query()->whereKey($client->id), $seller)
            ->where('edt_clients.is_active', true)
            ->exists();

        if (! $isMine) {
            throw new EdtRuleException('Ese cliente no está en tus zonas.');
        }

        $existing = $this->openDraft($seller, $client);
        if ($existing) {
            return $existing;
        }

        try {
            return EdtOrder::query()->create([
                'warehouse_id' => $seller->warehouse_id,
                'seller_id' => $seller->id,
                'client_id' => $client->id,
                'route_id' => $this->routeFor($seller, $client),
                'status' => EdtOrderStatus::Borrador,
                'payment_type' => EdtPaymentType::Contado,
                'order_date' => today(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro request lo creó al mismo tiempo.
            return $this->openDraft($seller, $client) ?? throw new EdtRuleException('No se pudo abrir el pedido; inténtalo de nuevo.');
        }
    }

    /**
     * Pone la cantidad de un producto (0 cajas y 0 sueltas lo quita).
     */
    public function setQuantity(EdtOrder $order, EdtProduct $product, int $boxes, int $units): EdtOrder
    {
        return DB::transaction(function () use ($order, $product, $boxes, $units): EdtOrder {
            $order = $this->lock($order);
            $this->assertSellerCanEdit($order);

            /** @var EdtOrderLine|null $line */
            $line = $order->lines()->where('product_id', $product->id)->first();
            $unitsPerBox = $line?->units_per_box ?? (int) $product->units_per_box;
            [$boxes, $units] = $this->normalize($boxes, $units, $unitsPerBox);
            $before = $line ? $this->quantityText($line->requested_boxes, $line->requested_units) : '0';

            if ($boxes + $units === 0) {
                $line?->delete();
            } else {
                if ($line === null) {
                    if (! $product->is_active) {
                        throw new EdtRuleException("{$product->description} está inactivo.");
                    }
                    $line = new EdtOrderLine(['order_id' => $order->id, 'product_id' => $product->id]);
                }

                // Mientras es borrador, el precio es el vigente del producto.
                if ($order->status === EdtOrderStatus::Borrador || ! $line->exists) {
                    $this->copyProduct($line, $product);
                }

                $line->fill([
                    'requested_boxes' => $boxes,
                    'requested_units' => $units,
                    'boxes' => $boxes,
                    'units' => $units,
                ]);
                $this->priceLine($line);
                $line->save();
            }

            if ($order->status === EdtOrderStatus::Enviado) {
                $this->log($order, 'Preventa corregida por el vendedor', [
                    'producto' => $product->code,
                    'antes' => $before,
                    'ahora' => $this->quantityText($boxes, $units),
                ]);
            }

            return $this->recalculate($order);
        });
    }

    /**
     * Forma de pago y nota. Crédito solo si el cliente lo tiene.
     */
    public function updateHeader(EdtOrder $order, EdtPaymentType $paymentType, ?string $note): EdtOrder
    {
        return DB::transaction(function () use ($order, $paymentType, $note): EdtOrder {
            $order = $this->lock($order);
            $this->assertSellerCanEdit($order);

            if ($paymentType === EdtPaymentType::Credito && ! $order->client()->value('credit_enabled')) {
                throw new EdtRuleException('Este cliente no tiene crédito.');
            }

            $order->fill([
                'payment_type' => $paymentType,
                'note' => filled($note) ? mb_strtoupper(trim($note)) : null,
            ])->save();

            return $order;
        });
    }

    /**
     * Envía la preventa a la bodega: copia el precio del día a cada línea,
     * le da número y la deja en ENVIADO.
     */
    public function submit(EdtOrder $order): EdtOrder
    {
        return DB::transaction(function () use ($order): EdtOrder {
            $order = $this->lock($order);

            if ($order->status !== EdtOrderStatus::Borrador) {
                throw new EdtRuleException('Este pedido ya se envió.');
            }

            $lines = $order->lines()->with('product')->get();

            if ($lines->isEmpty()) {
                throw new EdtRuleException('Agrega al menos un producto antes de enviar.');
            }

            $inactive = $lines->filter(fn (EdtOrderLine $line): bool => ! $line->product->is_active);
            if ($inactive->isNotEmpty()) {
                throw new EdtRuleException('Quita los productos inactivos: '.$inactive->pluck('product_code')->implode(', ').'.');
            }

            foreach ($lines as $line) {
                $this->copyProduct($line, $line->product);
                [$boxes, $units] = $this->normalize($line->requested_boxes, $line->requested_units, $line->units_per_box);
                $line->fill(['requested_boxes' => $boxes, 'requested_units' => $units, 'boxes' => $boxes, 'units' => $units]);
                $this->priceLine($line);
                $line->save();
            }

            $sequence = DB::selectOne("SELECT nextval('edt_order_number_seq') AS value")->value;

            $order->forceFill([
                'number' => 'PED-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'status' => EdtOrderStatus::Enviado,
                'order_date' => today(),
                'submitted_at' => now(),
            ]);

            $order = $this->recalculate($order);
            $this->log($order, 'Preventa enviada', ['total' => (string) $order->total, 'lineas' => $order->lines_count]);

            return $order;
        });
    }

    /**
     * El vendedor descarta su borrador (se borra) o anula una preventa que
     * la bodega no ha tocado. Anular dos veces no hace nada.
     *
     * @return EdtOrder|null null si era borrador (ya no existe)
     */
    public function cancel(EdtOrder $order, ?string $reason = null): ?EdtOrder
    {
        return DB::transaction(function () use ($order, $reason): ?EdtOrder {
            $order = $this->lock($order);

            if ($order->status === EdtOrderStatus::Anulado) {
                return $order;
            }

            $this->assertSellerCanEdit($order);

            if ($order->status === EdtOrderStatus::Borrador) {
                $order->delete();

                return null;
            }

            $order->forceFill([
                'status' => EdtOrderStatus::Anulado,
                'closed_at' => now(),
                'close_reason' => filled($reason) ? mb_strtoupper(trim($reason)) : null,
            ])->save();

            return $order;
        });
    }

    // ── Bodega ──────────────────────────────────────────────────────

    /**
     * La bodega baja la cantidad de una línea (hasta 0 si no hay). Nunca
     * más de lo que pidió el vendedor. Pasa la preventa a EN REVISIÓN: desde
     * ahí el vendedor ya no la cambia.
     */
    public function adjustLine(EdtOrderLine $line, int $boxes, int $units): EdtOrder
    {
        return DB::transaction(function () use ($line, $boxes, $units): EdtOrder {
            $order = $this->lock($line->order);
            $this->assertReviewable($order);

            /** @var EdtOrderLine $line */
            $line = $order->lines()->whereKey($line->id)->firstOrFail();
            [$boxes, $units] = $this->normalize($boxes, $units, $line->units_per_box);

            if ($boxes * $line->units_per_box + $units > $line->requestedUnits()) {
                throw new EdtRuleException('No se puede dar más de lo que pidió el vendedor ('.$this->quantityText($line->requested_boxes, $line->requested_units).').');
            }

            $before = $this->quantityText($line->boxes, $line->units);
            $line->fill(['boxes' => $boxes, 'units' => $units]);
            $this->priceLine($line);
            $line->save();

            $order->status = EdtOrderStatus::EnRevision;
            $order = $this->recalculate($order);

            $this->log($order, 'Línea ajustada por la bodega', [
                'producto' => $line->product_code,
                'pidio' => $this->quantityText($line->requested_boxes, $line->requested_units),
                'antes' => $before,
                'ahora' => $this->quantityText($boxes, $units),
            ]);

            return $order;
        });
    }

    /**
     * Aprueba la preventa: queda lista para facturar.
     */
    public function approve(EdtOrder $order): EdtOrder
    {
        return DB::transaction(function () use ($order): EdtOrder {
            $order = $this->lock($order);
            $this->assertReviewable($order);

            if ((int) $order->lines_count === 0) {
                throw new EdtRuleException('No quedó ningún producto: recházala en lugar de aprobarla.');
            }

            $order->forceFill([
                'status' => EdtOrderStatus::Aprobado,
                'reviewed_at' => now(),
                'reviewed_by' => Auth::id(),
            ])->save();

            $this->log($order, 'Preventa aprobada', ['total' => (string) $order->total]);

            return $order;
        });
    }

    /**
     * Rechaza la preventa completa, con motivo.
     */
    public function reject(EdtOrder $order, string $reason): EdtOrder
    {
        if (blank($reason)) {
            throw new EdtRuleException('Escribe el motivo del rechazo.');
        }

        return DB::transaction(function () use ($order, $reason): EdtOrder {
            $order = $this->lock($order);
            $this->assertReviewable($order);

            $order->forceFill([
                'status' => EdtOrderStatus::Rechazado,
                'reviewed_at' => now(),
                'reviewed_by' => Auth::id(),
                'closed_at' => now(),
                'close_reason' => mb_strtoupper(trim($reason)),
            ])->save();

            return $order;
        });
    }

    // ── Internos ────────────────────────────────────────────────────

    private function openDraft(EdtSeller $seller, EdtClient $client): ?EdtOrder
    {
        return EdtOrder::query()
            ->where('seller_id', $seller->id)
            ->where('client_id', $client->id)
            ->where('status', EdtOrderStatus::Borrador)
            ->first();
    }

    /**
     * La ruta vigente del vendedor que cubre el municipio del cliente (para
     * los reportes por ruta). Null si no hay.
     */
    private function routeFor(EdtSeller $seller, EdtClient $client): ?int
    {
        return EdtRouteAssignment::query()
            ->currentOn()
            ->where('seller_id', $seller->id)
            ->whereExists(fn ($query) => $query
                ->from('edt_route_municipality')
                ->whereColumn('edt_route_municipality.route_id', 'edt_route_assignments.route_id')
                ->where('edt_route_municipality.municipality_id', $client->municipality_id))
            ->orderByDesc('starts_on')
            ->value('route_id');
    }

    private function lock(EdtOrder $order): EdtOrder
    {
        return EdtOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function assertSellerCanEdit(EdtOrder $order): void
    {
        if (! $order->status->isEditableBySeller()) {
            throw new EdtRuleException(match ($order->status) {
                EdtOrderStatus::EnRevision => 'La bodega ya está revisando esta preventa; ya no se puede cambiar.',
                default => "La preventa está {$order->status->getLabel()}; ya no se puede cambiar.",
            });
        }
    }

    private function assertReviewable(EdtOrder $order): void
    {
        if (! $order->status->isReviewable()) {
            throw new EdtRuleException("La preventa está {$order->status->getLabel()}; ya no se puede revisar.");
        }
    }

    /**
     * Sueltas que completan una caja pasan a cajas. Nada negativo.
     *
     * @return array{0: int, 1: int}
     */
    private function normalize(int $boxes, int $units, int $unitsPerBox): array
    {
        if ($boxes < 0 || $units < 0) {
            throw new EdtRuleException('Las cantidades no pueden ser negativas.');
        }

        return [$boxes + intdiv($units, $unitsPerBox), $units % $unitsPerBox];
    }

    /**
     * Copia a la línea el producto y su precio vigente.
     */
    private function copyProduct(EdtOrderLine $line, EdtProduct $product): void
    {
        $line->fill([
            'product_code' => $product->code,
            'description' => $product->description,
            'units_per_box' => (int) $product->units_per_box,
            'isv_pct' => (string) $product->isv_pct,
            'list_price' => (string) $product->list_price,
            'box_cost' => $product->priceQuote()->boxCost,
        ]);
    }

    /**
     * Precios y montos de la línea con su copia del producto: escala por las
     * cajas que quedan; sueltas al precio de detalle por unidad. El total es
     * con ISV (lo que paga el cliente); la base sin ISV se saca de él, así
     * el total nunca se mueve por redondeo.
     */
    private function priceLine(EdtOrderLine $line): void
    {
        $quote = $this->pricing->quote(
            (string) $line->list_price,
            (int) $line->units_per_box,
            (string) $line->isv_pct,
            (string) $line->box_cost,
            $this->pricing->activeTiers(),
        );

        $tier = $quote->wholesaleForBoxes((int) $line->boxes);
        $boxPrice = BigDecimal::of($tier['box_price'] ?? $quote->retailBoxPrice);
        $unitPrice = BigDecimal::of($quote->retailUnitPrice);

        $total = $boxPrice->multipliedBy($line->boxes)->plus($unitPrice->multipliedBy($line->units));
        $base = $total->dividedBy(BigDecimal::one()->plus(BigDecimal::of((string) $line->isv_pct)->dividedBy(100, 4)), 2, RoundingMode::HalfUp);

        $requestedTier = $quote->wholesaleForBoxes((int) $line->requested_boxes);
        $requestedTotal = BigDecimal::of($requestedTier['box_price'] ?? $quote->retailBoxPrice)
            ->multipliedBy($line->requested_boxes)
            ->plus($unitPrice->multipliedBy($line->requested_units));

        $boxCost = BigDecimal::of((string) $line->box_cost);
        $cost = $boxCost->multipliedBy($line->boxes)
            ->plus(BigDecimal::of($quote->unitCost)->multipliedBy($line->units))
            ->toScale(2, RoundingMode::HalfUp);

        $line->fill([
            'tier_name' => $tier['name'] ?? null,
            'tier_discount_pct' => $tier['discount_pct'] ?? null,
            'box_price' => (string) $boxPrice->toScale(2),
            'unit_price' => (string) $unitPrice->toScale(2),
            'subtotal' => (string) $base,
            'isv' => (string) $total->minus($base)->toScale(2),
            'total' => (string) $total->toScale(2),
            'requested_total' => (string) $requestedTotal->toScale(2),
            'cost_total' => (string) $cost,
        ]);
    }

    /**
     * Totales del pedido sumados en SQL (una consulta).
     */
    private function recalculate(EdtOrder $order): EdtOrder
    {
        $sums = $order->lines()->toBase()->selectRaw(<<<'SQL'
            COALESCE(SUM(total) FILTER (WHERE isv_pct = 0), 0) AS exempt_total,
            COALESCE(SUM(subtotal) FILTER (WHERE isv_pct = 15), 0) AS taxable_15,
            COALESCE(SUM(isv) FILTER (WHERE isv_pct = 15), 0) AS isv_15,
            COALESCE(SUM(subtotal) FILTER (WHERE isv_pct = 18), 0) AS taxable_18,
            COALESCE(SUM(isv) FILTER (WHERE isv_pct = 18), 0) AS isv_18,
            COALESCE(SUM(total), 0) AS total,
            COALESCE(SUM(requested_total), 0) AS requested_total,
            COALESCE(SUM(cost_total), 0) AS cost_total,
            COUNT(*) FILTER (WHERE boxes + units > 0) AS lines_count
            SQL)->first();

        $order->forceFill((array) $sums)->save();

        return $order;
    }

    private function quantityText(int $boxes, int $units): string
    {
        return trim(($boxes > 0 ? "{$boxes} CJ" : '').($units > 0 ? " {$units} UN" : '')) ?: '0';
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(EdtOrder $order, string $description, array $properties = []): void
    {
        activity(EdtModule::LOG_NAME)
            ->performedOn($order)
            ->causedBy(Auth::user())
            ->withProperties($properties)
            ->log($description);
    }

    /**
     * Preventas que ve un usuario: el vendedor solo las suyas (y sus
     * borradores); los demás, las de sus bodegas sin borradores ajenos.
     */
    public static function visibleTo(Builder $orders, ?User $user): Builder
    {
        if ($user === null) {
            return $orders->whereRaw('false');
        }

        if ($seller = $user->edtSeller) {
            return $orders->where('edt_orders.seller_id', $seller->id);
        }

        if ($user->hasRole(EdtModule::SELLER_ROLE)) {
            return $orders->whereRaw('false');
        }

        return $orders->where('edt_orders.status', '!=', EdtOrderStatus::Borrador->value);
    }
}
