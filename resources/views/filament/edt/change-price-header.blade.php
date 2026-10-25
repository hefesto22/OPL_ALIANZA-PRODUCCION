{{--
    Encabezado de «Cambiar precio»: el producto y los datos que NO se cambian
    aquí (ISV, unidades por caja, proveedor), con el último cambio de precio.
    Lo arma ChangePriceAction::header(). Estilos edt-ch con las variables de
    Filament, para claro y oscuro.
--}}
<style>
    .edt-ch { display: flex; flex-direction: column; gap: .625rem; margin-top: .25rem; }
    .edt-ch-desc { font-size: .9375rem; font-weight: 600; color: var(--gray-700); }
    .dark .edt-ch-desc { color: var(--gray-200); }
    .edt-ch-row { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .5rem; }
    .edt-ch-chip { display: inline-flex; align-items: center; gap: .3rem; border-radius: 9999px; padding: .1875rem .625rem; font-size: .75rem; line-height: 1.125rem; font-weight: 600; background: var(--gray-100); color: var(--gray-700); }
    .dark .edt-ch-chip { background: rgb(255 255 255 / .07); color: var(--gray-200); }
    .edt-ch-chip svg { width: .875rem; height: .875rem; opacity: .7; }
    .edt-ch-chip-isv { background: color-mix(in oklab, var(--primary-500) 14%, transparent); color: var(--primary-700); }
    .dark .edt-ch-chip-isv { color: var(--primary-300); }
    .edt-ch-note { display: inline-flex; align-items: center; gap: .3rem; font-size: .75rem; color: var(--gray-500); }
    .edt-ch-note svg { width: .875rem; height: .875rem; }
    .edt-ch-last { font-size: .75rem; color: var(--gray-500); }
    .edt-ch-last strong { font-weight: 600; color: var(--gray-700); }
    .dark .edt-ch-last strong { color: var(--gray-300); }
</style>

<div class="edt-ch">
    <div class="edt-ch-desc">{{ $description }}</div>

    <div class="edt-ch-row">
        <span class="edt-ch-chip edt-ch-chip-isv">
            <x-filament::icon icon="heroicon-m-receipt-percent" />
            ISV {{ $isv }}
        </span>
        <span class="edt-ch-chip">
            <x-filament::icon icon="heroicon-m-cube" />
            {{ $unitsPerBox }} {{ $unitsPerBox === 1 ? 'unidad' : 'unidades' }} por caja
        </span>
        @if ($supplier)
            <span class="edt-ch-chip">
                <x-filament::icon icon="heroicon-m-truck" />
                {{ $supplier }}
            </span>
        @endif
        <span class="edt-ch-note">
            <x-filament::icon icon="heroicon-m-pencil-square" />
            El ISV y las unidades se cambian en «Editar»
        </span>
    </div>

    @if ($lastChange)
        <div class="edt-ch-last">
            Último cambio: <strong>{{ $lastChange['date'] }}</strong> · {{ $lastChange['reason'] }}
        </div>
    @endif
</div>
