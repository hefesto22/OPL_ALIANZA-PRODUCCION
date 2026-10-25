{{--
    Paso 1 de la preventa (celular): buscar y elegir el cliente.
    Datos: PickEdtOrderClient::getViewData(). Estilos edt-pk.
--}}
<x-filament-panels::page>
    <style>
        .edt-pk { display: flex; flex-direction: column; gap: .75rem; }
        .edt-pk-list { display: grid; gap: .5rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 768px) { .edt-pk-list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .edt-pk-card { min-width: 0; display: flex; align-items: center; gap: .75rem; width: 100%; text-align: left; border-radius: .75rem; padding: .875rem 1rem; background: #fff; border: 1px solid var(--gray-200); transition: border-color .15s, background .15s; }
        .dark .edt-pk-card { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .edt-pk-card:hover, .edt-pk-card:focus-visible { border-color: var(--primary-500); outline: none; }
        .edt-pk-avatar { flex-shrink: 0; display: grid; place-items: center; width: 2.5rem; height: 2.5rem; border-radius: 9999px; font-weight: 700; font-size: .875rem; background: color-mix(in oklab, var(--primary-500) 15%, transparent); color: var(--primary-700); }
        .dark .edt-pk-avatar { color: var(--primary-300); }
        .edt-pk-body { min-width: 0; flex: 1; }
        .edt-pk-name { font-weight: 600; font-size: .9375rem; line-height: 1.25rem; color: var(--gray-950); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dark .edt-pk-name { color: #fff; }
        .edt-pk-meta { margin-top: .125rem; font-size: .75rem; color: var(--gray-500); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .edt-pk-tags { display: flex; flex-direction: column; align-items: flex-end; gap: .25rem; flex-shrink: 0; }
        .edt-pk-tag { border-radius: 9999px; padding: .0625rem .5rem; font-size: .6875rem; font-weight: 700; letter-spacing: .02em; }
        .edt-pk-tag-today { background: color-mix(in oklab, var(--success-500) 16%, transparent); color: var(--success-700); }
        .dark .edt-pk-tag-today { color: var(--success-400); }
        .edt-pk-tag-draft { background: color-mix(in oklab, var(--warning-500) 18%, transparent); color: var(--warning-700); }
        .dark .edt-pk-tag-draft { color: var(--warning-400); }
        .edt-pk-tag-credit { background: var(--gray-100); color: var(--gray-600); }
        .dark .edt-pk-tag-credit { background: rgb(255 255 255 / .08); color: var(--gray-300); }
        .edt-pk-empty { border: 1px dashed var(--gray-300); border-radius: .75rem; padding: 1.5rem; text-align: center; color: var(--gray-500); font-size: .875rem; }
        .dark .edt-pk-empty { border-color: rgb(255 255 255 / .15); }
    </style>

    <div class="edt-pk">
        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
            <x-filament::input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Buscar por nombre, negocio o código"
                autocomplete="off"
            />
        </x-filament::input.wrapper>

        <div class="edt-pk-list" wire:loading.class="opacity-60" wire:target="search">
            @forelse ($clients as $client)
                <button type="button" class="edt-pk-card" wire:click="start({{ $client->id }})" wire:loading.attr="disabled" wire:key="client-{{ $client->id }}">
                    <span class="edt-pk-avatar">{{ mb_substr($client->name, 0, 2) }}</span>
                    <span class="edt-pk-body">
                        <span class="edt-pk-name" style="display:block">{{ $client->name }}</span>
                        <span class="edt-pk-meta" style="display:block">
                            {{ $client->code }}@if ($client->business_name) · {{ $client->business_name }}@endif · {{ $client->municipality?->name }}
                        </span>
                    </span>
                    <span class="edt-pk-tags">
                        @if ($drafts->has($client->id))
                            <span class="edt-pk-tag edt-pk-tag-draft">BORRADOR</span>
                        @endif
                        @if ($client->is_today)
                            <span class="edt-pk-tag edt-pk-tag-today">HOY</span>
                        @endif
                        @if ($client->credit_enabled)
                            <span class="edt-pk-tag edt-pk-tag-credit">CRÉDITO</span>
                        @endif
                    </span>
                </button>
            @empty
                <div class="edt-pk-empty">
                    @if (trim($search) !== '')
                        No hay clientes de tus zonas con «{{ $search }}».
                    @else
                        No tienes clientes asignados. Pide a tu encargado que te asigne una ruta.
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
