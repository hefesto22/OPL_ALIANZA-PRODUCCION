{{--
    Paso 2 de la preventa (celular): armar el pedido. Datos:
    TakeEdtOrder::getViewData(). Cada − / + o cantidad escrita se guarda
    en el servidor al momento. Estilos edt-to con las variables de Filament.
--}}
<x-filament-panels::page>
    <style>
        .edt-to { display: flex; flex-direction: column; gap: .875rem; font-size: .875rem; color: var(--gray-950); }
        .dark .edt-to { color: #fff; }
        .edt-to-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .edt-to-muted { color: var(--gray-500); }
        .dark .edt-to-muted { color: var(--gray-400); }
        .edt-to-card { border-radius: .875rem; background: #fff; border: 1px solid var(--gray-200); }
        .dark .edt-to-card { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }

        .edt-to-client { padding: 1rem; display: flex; flex-direction: column; gap: .75rem; }
        .edt-to-client-top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .edt-to-client-top > :last-child { flex-shrink: 0; }
        .edt-to-client-name { font-size: 1.0625rem; font-weight: 700; line-height: 1.375rem; }
        .edt-to-client-meta { font-size: .75rem; margin-top: .125rem; }
        .edt-to-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
        .edt-to-label { font-size: .75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: .04em; }
        .edt-to-seg { display: inline-flex; border-radius: .5rem; border: 1px solid var(--gray-200); overflow: hidden; }
        .dark .edt-to-seg { border-color: rgb(255 255 255 / .12); }
        .edt-to-seg button { padding: .375rem .875rem; font-size: .8125rem; font-weight: 600; color: var(--gray-700); }
        .dark .edt-to-seg button { color: var(--gray-200); }
        .edt-to-seg button + button { border-left: 1px solid var(--gray-200); }
        .dark .edt-to-seg button + button { border-color: rgb(255 255 255 / .12); }
        .edt-to-seg button[aria-pressed="true"] { background: var(--primary-500); color: #fff; }
        .edt-to-seg button:disabled { opacity: .45; }

        .edt-to-tools { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .edt-to-tools > :first-child { flex: 1 1 14rem; }
        .edt-to-chip { display: inline-flex; align-items: center; gap: .375rem; border-radius: 9999px; padding: .375rem .75rem; font-size: .8125rem; font-weight: 600; border: 1px solid var(--gray-200); color: var(--gray-700); background: #fff; }
        .dark .edt-to-chip { border-color: rgb(255 255 255 / .12); color: var(--gray-200); background: var(--gray-900); }
        .edt-to-chip[aria-pressed="true"] { border-color: var(--primary-500); color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 10%, transparent); }
        .dark .edt-to-chip[aria-pressed="true"] { color: var(--primary-300); }

        .edt-to-list { display: grid; gap: .625rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .edt-to-list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .edt-to-product { padding: .875rem 1rem; display: flex; flex-direction: column; gap: .625rem; }
        .edt-to-product.is-in { border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500); }
        .edt-to-product-top { display: flex; justify-content: space-between; gap: .5rem; }
        .edt-to-product-name { font-weight: 600; line-height: 1.25rem; }
        .edt-to-product-meta { font-size: .75rem; margin-top: .125rem; }
        .edt-to-isv { flex-shrink: 0; align-self: flex-start; border-radius: 9999px; padding: .0625rem .5rem; font-size: .6875rem; font-weight: 700; background: var(--gray-100); color: var(--gray-600); }
        .dark .edt-to-isv { background: rgb(255 255 255 / .08); color: var(--gray-300); }
        .edt-to-prices { display: flex; flex-wrap: wrap; gap: .25rem .875rem; font-size: .8125rem; }
        .edt-to-prices strong { font-weight: 700; }
        .edt-to-tiers { display: flex; flex-wrap: wrap; gap: .375rem; }
        .edt-to-tier { border-radius: .375rem; padding: .125rem .5rem; font-size: .75rem; background: var(--gray-50); color: var(--gray-600); border: 1px solid var(--gray-200); }
        .dark .edt-to-tier { background: rgb(255 255 255 / .04); color: var(--gray-300); border-color: rgb(255 255 255 / .08); }
        .edt-to-tier.is-on { background: color-mix(in oklab, var(--success-500) 14%, transparent); color: var(--success-700); border-color: transparent; font-weight: 700; }
        .dark .edt-to-tier.is-on { color: var(--success-400); }

        .edt-to-qty { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: flex-end; justify-content: space-between; border-top: 1px dashed var(--gray-200); padding-top: .625rem; }
        .dark .edt-to-qty { border-color: rgb(255 255 255 / .1); }
        .edt-to-steppers { display: flex; gap: .875rem; }
        .edt-to-stepper { display: flex; flex-direction: column; gap: .25rem; }
        .edt-to-stepper-box { display: inline-flex; align-items: center; border-radius: .625rem; border: 1px solid var(--gray-300); overflow: hidden; }
        .dark .edt-to-stepper-box { border-color: rgb(255 255 255 / .15); }
        .edt-to-stepper-box button { width: 2.5rem; height: 2.5rem; display: grid; place-items: center; font-size: 1.25rem; font-weight: 600; color: var(--gray-700); background: var(--gray-50); }
        .dark .edt-to-stepper-box button { color: var(--gray-200); background: rgb(255 255 255 / .04); }
        .edt-to-stepper-box button:active { background: var(--gray-200); }
        .edt-to-stepper-box input { width: 3.25rem; height: 2.5rem; text-align: center; font-weight: 700; font-size: 1rem; border: 0; background: transparent; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
        .edt-to-stepper-box input::-webkit-outer-spin-button, .edt-to-stepper-box input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .edt-to-stepper-box input:focus { outline: 2px solid var(--primary-500); outline-offset: -2px; }
        .edt-to-line { display: flex; align-items: center; justify-content: space-between; gap: .5rem; width: 100%; }
        .edt-to-line-total { font-size: 1.0625rem; font-weight: 700; }
        .edt-to-line-tier { border-radius: 9999px; padding: .0625rem .5rem; font-size: .6875rem; font-weight: 700; background: var(--gray-100); color: var(--gray-600); }
        .dark .edt-to-line-tier { background: rgb(255 255 255 / .08); color: var(--gray-300); }
        .edt-to-line-tier.is-wholesale { background: color-mix(in oklab, var(--success-500) 14%, transparent); color: var(--success-700); }
        .dark .edt-to-line-tier.is-wholesale { color: var(--success-400); }

        .edt-to-empty { border: 1px dashed var(--gray-300); border-radius: .75rem; padding: 1.5rem; text-align: center; color: var(--gray-500); }
        .dark .edt-to-empty { border-color: rgb(255 255 255 / .15); }

        .edt-to-bar { position: sticky; bottom: 0; z-index: 20; margin: 0 -.25rem; padding: .75rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; border-radius: .875rem .875rem 0 0; background: #fff; border: 1px solid var(--gray-200); box-shadow: 0 -8px 24px -12px rgb(0 0 0 / .25); }
        .dark .edt-to-bar { background: var(--gray-900); border-color: rgb(255 255 255 / .12); }
        .edt-to-bar-total { font-size: 1.375rem; font-weight: 800; line-height: 1.75rem; }
        .edt-to-bar-sub { font-size: .75rem; }
    </style>

    <div class="edt-to">
        {{-- Cliente, pago y nota --}}
        <section class="edt-to-card edt-to-client">
            <div class="edt-to-client-top">
                <div style="min-width:0">
                    <div class="edt-to-client-name">{{ $order->client->name }}</div>
                    <div class="edt-to-client-meta edt-to-muted">
                        {{ $order->client->code }}@if ($order->client->business_name) · {{ $order->client->business_name }}@endif · {{ $order->client->municipality?->name }}
                    </div>
                </div>
                <x-filament::badge :color="$order->status->getColor()">{{ $order->status->getLabel() }}</x-filament::badge>
            </div>

            <div class="edt-to-row">
                <span class="edt-to-label">Pago</span>
                <span class="edt-to-seg" role="group">
                    @foreach (\App\Enums\Edt\EdtPaymentType::cases() as $type)
                        <button type="button"
                            wire:click="setPayment('{{ $type->value }}')"
                            aria-pressed="{{ $order->payment_type === $type ? 'true' : 'false' }}"
                            @disabled($type === \App\Enums\Edt\EdtPaymentType::Credito && ! $order->client->credit_enabled)
                        >{{ $type->getLabel() }}</button>
                    @endforeach
                </span>
                @if ($order->client->credit_enabled)
                    <span class="edt-to-muted" style="font-size:.75rem">
                        {{ collect([
                            $order->client->credit_limit ? 'Límite L '.number_format((float) $order->client->credit_limit, 2) : null,
                            $order->client->credit_days ? $order->client->credit_days.' días' : null,
                        ])->filter()->implode(' · ') ?: 'Con crédito' }}
                    </span>
                @else
                    <span class="edt-to-muted" style="font-size:.75rem">Sin crédito</span>
                @endif
            </div>

            <x-filament::input.wrapper prefix-icon="heroicon-m-chat-bubble-bottom-center-text">
                <x-filament::input type="text" wire:model.blur="note" maxlength="255" placeholder="Nota para la bodega (opcional)" style="text-transform:uppercase" />
            </x-filament::input.wrapper>
        </section>

        {{-- Buscador --}}
        <div class="edt-to-tools">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar producto por nombre o código" autocomplete="off" />
            </x-filament::input.wrapper>
            <button type="button" class="edt-to-chip" wire:click="$toggle('onlyInOrder')" aria-pressed="{{ $onlyInOrder ? 'true' : 'false' }}">
                <x-filament::icon icon="heroicon-m-shopping-cart" style="width:1rem;height:1rem" />
                Lo que lleva ({{ $order->lines_count }})
            </button>
        </div>

        {{-- Productos --}}
        <div class="edt-to-list">
            @forelse ($products as $product)
                @php
                    $isIn = $product['boxes'] + $product['units'] > 0;
                @endphp
                <article class="edt-to-card edt-to-product {{ $isIn ? 'is-in' : '' }}" wire:key="product-{{ $product['id'] }}">
                    <div class="edt-to-product-top">
                        <div style="min-width:0">
                            <div class="edt-to-product-name">{{ $product['description'] }}</div>
                            <div class="edt-to-product-meta edt-to-muted">{{ $product['code'] }} · {{ $product['unitsPerBox'] }} u. por caja</div>
                        </div>
                        <span class="edt-to-isv">{{ $product['isv'] === 0 ? 'EXENTO' : 'ISV '.$product['isv'].'%' }}</span>
                    </div>

                    <div class="edt-to-prices">
                        <span>Caja <strong class="edt-to-num">L {{ number_format((float) $product['boxPrice'], 2) }}</strong></span>
                        <span>Unidad <strong class="edt-to-num">L {{ number_format((float) $product['unitPrice'], 2) }}</strong></span>
                    </div>

                    @if (count($product['tiers']))
                        <div class="edt-to-tiers">
                            @foreach ($product['tiers'] as $tier)
                                @php
                                    $isOn = $product['tierName'] !== null && $product['boxes'] >= $tier['min'] && ($loop->last || $product['boxes'] < $product['tiers'][$loop->index + 1]['min']);
                                @endphp
                                <span class="edt-to-tier edt-to-num {{ $isOn ? 'is-on' : '' }}">{{ $tier['min'] }}+ cajas · L {{ number_format((float) $tier['price'], 2) }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="edt-to-qty">
                        <div class="edt-to-steppers">
                            <div class="edt-to-stepper">
                                <span class="edt-to-label">Cajas</span>
                                <span class="edt-to-stepper-box">
                                    <button type="button" aria-label="Quitar una caja" wire:click="step({{ $product['id'] }}, 'boxes', -1)">−</button>
                                    <input type="number" inputmode="numeric" min="0" value="{{ $product['boxes'] }}"
                                        aria-label="Cajas de {{ $product['description'] }}"
                                        wire:change="setQuantity({{ $product['id'] }}, $event.target.value, {{ $product['units'] }})">
                                    <button type="button" aria-label="Agregar una caja" wire:click="step({{ $product['id'] }}, 'boxes', 1)">+</button>
                                </span>
                            </div>
                            @if ($product['unitsPerBox'] > 1)
                                <div class="edt-to-stepper">
                                    <span class="edt-to-label">Sueltas</span>
                                    <span class="edt-to-stepper-box">
                                        <button type="button" aria-label="Quitar una unidad" wire:click="step({{ $product['id'] }}, 'units', -1)">−</button>
                                        <input type="number" inputmode="numeric" min="0" value="{{ $product['units'] }}"
                                            aria-label="Unidades sueltas de {{ $product['description'] }}"
                                            wire:change="setQuantity({{ $product['id'] }}, {{ $product['boxes'] }}, $event.target.value)">
                                        <button type="button" aria-label="Agregar una unidad" wire:click="step({{ $product['id'] }}, 'units', 1)">+</button>
                                    </span>
                                </div>
                            @endif
                        </div>

                        @if ($isIn)
                            <div class="edt-to-line">
                                <span class="edt-to-line-tier {{ $product['tierName'] ? 'is-wholesale' : '' }}">{{ $product['tierName'] ?? 'DETALLE' }}</span>
                                <span class="edt-to-line-total edt-to-num">L {{ number_format((float) $product['lineTotal'], 2) }}</span>
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="edt-to-empty">
                    @if ($onlyInOrder)
                        Todavía no lleva productos.
                    @else
                        No hay productos con «{{ $search }}».
                    @endif
                </div>
            @endforelse
        </div>

        {{-- Total y enviar --}}
        <div class="edt-to-bar">
            <div>
                <div class="edt-to-bar-total edt-to-num">L {{ number_format((float) $order->total, 2) }}</div>
                <div class="edt-to-bar-sub edt-to-muted">
                    {{ $order->lines_count }} {{ $order->lines_count === 1 ? 'producto' : 'productos' }}
                    <span wire:loading> · guardando…</span>
                    @unless ($isDraft)
                        <span wire:loading.remove> · los cambios se guardan solos</span>
                    @endunless
                </div>
            </div>

            @if ($isDraft)
                {{ $this->submitAction }}
            @else
                <x-filament::button tag="a" :href="\App\Filament\Resources\Edt\Orders\EdtOrderResource::getUrl('index')" color="gray" icon="heroicon-m-check">
                    Listo
                </x-filament::button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
