{{--
    Reporte de ventas por producto (pantalla).
    Los números los arma App\Services\Reports\ProductSalesReportService; aquí solo se pintan.
    Estilos con prefijo psr- y las variables de color de Filament: se ve igual en claro
    y oscuro sin compilar un tema (mismo patrón que las vistas del EDT).
--}}
<x-filament-panels::page>
    <style>
        .psr { display: flex; flex-direction: column; gap: 1rem; font-size: .875rem; line-height: 1.25rem; color: var(--gray-950); }
        .dark .psr { color: #fff; }
        .psr-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .psr-muted { color: var(--gray-500); }
        .dark .psr-muted { color: var(--gray-400); }

        .psr-form-actions { display: flex; justify-content: flex-end; margin-top: 1rem; }

        .psr-meta { display: flex; flex-wrap: wrap; gap: .375rem .75rem; align-items: center; font-size: .8125rem; color: var(--gray-600); }
        .dark .psr-meta { color: var(--gray-300); }
        .psr-meta strong { font-weight: 600; color: var(--gray-950); }
        .dark .psr-meta strong { color: #fff; }
        .psr-dot { color: var(--gray-300); }
        .dark .psr-dot { color: var(--gray-600); }

        .psr-alert { border-radius: .75rem; padding: .75rem 1rem; font-size: .8125rem; background: color-mix(in oklab, var(--warning-500) 12%, transparent); border: 1px solid color-mix(in oklab, var(--warning-500) 35%, transparent); color: var(--warning-800); }
        .dark .psr-alert { color: var(--warning-300); }
        .psr-alert strong { font-weight: 700; }

        .psr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: .75rem; }
        .psr-card { border-radius: .75rem; padding: .875rem 1rem; background: #fff; border: 1px solid var(--gray-200); }
        .dark .psr-card { background: rgb(255 255 255 / .04); border-color: rgb(255 255 255 / .1); }
        .psr-card-label { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--gray-500); }
        .dark .psr-card-label { color: var(--gray-400); }
        .psr-card-value { margin-top: .25rem; font-size: 1.375rem; line-height: 1.75rem; font-weight: 700; letter-spacing: -.01em; }
        .psr-card-sub { margin-top: .125rem; font-size: .75rem; color: var(--gray-500); }
        .dark .psr-card-sub { color: var(--gray-400); }
        .psr-card-net { background: color-mix(in oklab, var(--success-500) 9%, transparent); border-color: color-mix(in oklab, var(--success-500) 35%, transparent); }
        .dark .psr-card-net { background: color-mix(in oklab, var(--success-500) 12%, transparent); }
        .psr-card-net .psr-card-value { color: var(--success-700); }
        .dark .psr-card-net .psr-card-value { color: var(--success-400); }
        .psr-card-ret .psr-card-value { color: var(--danger-700); }
        .dark .psr-card-ret .psr-card-value { color: var(--danger-400); }

        .psr-panel { border-radius: .75rem; background: #fff; border: 1px solid var(--gray-200); overflow: hidden; }
        .dark .psr-panel { background: var(--gray-900); border-color: rgb(255 255 255 / .1); }
        .psr-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem .75rem; padding: .75rem 1rem; border-bottom: 1px solid var(--gray-200); }
        .dark .psr-toolbar { border-color: rgb(255 255 255 / .1); }
        .psr-search { width: 100%; max-width: 20rem; border-radius: .5rem; border: 1px solid var(--gray-300); background: transparent; padding: .375rem .75rem; font-size: .875rem; color: inherit; }
        .dark .psr-search { border-color: rgb(255 255 255 / .15); }
        .psr-search:focus { outline: 2px solid var(--primary-500); outline-offset: -1px; }

        .psr-tablewrap { overflow-x: auto; }
        .psr-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .8125rem; }
        .psr-table th { padding: .625rem .625rem; font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; text-align: right; white-space: nowrap; color: var(--gray-500); background: var(--gray-50); border-bottom: 1px solid var(--gray-200); }
        .dark .psr-table th { color: var(--gray-400); background: var(--gray-800); border-color: rgb(255 255 255 / .1); }
        .psr-table th.psr-left, .psr-table td.psr-left { text-align: left; }
        .psr-table td { padding: .5rem .625rem; text-align: right; vertical-align: top; border-top: 1px solid var(--gray-100); background: #fff; }
        .dark .psr-table td { border-color: rgb(255 255 255 / .06); background: var(--gray-900); }
        .psr-table tbody tr:hover td { background: color-mix(in oklab, var(--primary-500) 6%, #fff); }
        .dark .psr-table tbody tr:hover td { background: color-mix(in oklab, var(--primary-500) 8%, var(--gray-900)); }
        /* La columna del producto queda fija al desplazar la tabla de lado (celular). */
        .psr-table .psr-sticky { position: sticky; left: 0; z-index: 1; box-shadow: 1px 0 0 var(--gray-200); }
        .dark .psr-table .psr-sticky { box-shadow: 1px 0 0 rgb(255 255 255 / .1); }
        .psr-code { display: block; margin-top: .125rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .6875rem; font-weight: 400; color: var(--gray-500); }
        .dark .psr-code { color: var(--gray-400); }
        .psr-product { min-width: 12rem; max-width: 18rem; font-weight: 500; }
        @media (max-width: 40rem) { .psr-product { min-width: 9rem; } }
        .psr-udc { display: inline-block; margin-left: .375rem; border-radius: .375rem; padding: 0 .375rem; font-size: .6875rem; font-weight: 600; background: var(--gray-100); color: var(--gray-600); }
        .dark .psr-udc { background: rgb(255 255 255 / .08); color: var(--gray-300); }
        .psr-bonus { color: var(--success-700); }
        .dark .psr-bonus { color: var(--success-400); }
        .psr-ret { color: var(--danger-700); }
        .dark .psr-ret { color: var(--danger-400); }
        .psr-net { font-weight: 700; }
        .psr-group-start { border-left: 1px solid var(--gray-200); }
        .dark .psr-group-start { border-left-color: rgb(255 255 255 / .1); }
        .psr-table tfoot td { padding: .75rem .625rem; font-weight: 700; border-top: 2px solid var(--gray-300); background: var(--gray-50); }
        .dark .psr-table tfoot td { border-top-color: rgb(255 255 255 / .2); background: var(--gray-800); }

        .psr-note { font-size: .75rem; color: var(--gray-500); line-height: 1.125rem; }
        .dark .psr-note { color: var(--gray-400); }
        .psr-empty { display: flex; align-items: center; gap: .75rem; border: 1px dashed var(--gray-300); border-radius: .75rem; padding: 1.25rem; color: var(--gray-500); }
        .dark .psr-empty { border-color: rgb(255 255 255 / .15); color: var(--gray-400); }
        .psr-empty svg { width: 1.75rem; height: 1.75rem; flex-shrink: 0; }
    </style>

    <x-filament::section>
        <form wire:submit="generate">
            {{ $this->form }}

            <div class="psr-form-actions">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
                    Generar reporte
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    @php
        $report = $this->report;
    @endphp

    @if ($report)
        @php
            $totals = $report['totals'];
            $from = \Illuminate\Support\Carbon::parse($report['filters']['date_from'])->format('d/m/Y');
            $to = \Illuminate\Support\Carbon::parse($report['filters']['date_to'])->format('d/m/Y');
            $warehouseCodes = collect($report['warehouses'])->pluck('code')->implode(', ');
        @endphp

        <div class="psr" wire:key="psr-{{ md5(json_encode($report['filters'])) }}">
            <div class="psr-meta">
                <span>Del <strong>{{ $from }}</strong> al <strong>{{ $to }}</strong></span>
                <span class="psr-dot">•</span>
                <span>Contado por <strong>{{ $report['date_field_label'] }}</strong></span>
                <span class="psr-dot">•</span>
                <span>Bodegas: <strong>{{ $warehouseCodes !== '' ? $warehouseCodes : '—' }}</strong></span>
                <span class="psr-dot">•</span>
                <span>Generado {{ $report['generated_at'] }}</span>
            </div>

            @if ($report['open_return_windows'] > 0)
                <div class="psr-alert">
                    <strong>Este período todavía puede cambiar.</strong>
                    {{ $report['open_return_windows'] }} {{ $report['open_return_windows'] === 1 ? 'manifiesto sigue' : 'manifiestos siguen' }}
                    con la ventana de devoluciones abierta: lo devuelto puede aumentar en los próximos días.
                </div>
            @endif

            <div class="psr-cards">
                <div class="psr-card">
                    <div class="psr-card-label">Facturas</div>
                    <div class="psr-card-value psr-num">{{ number_format($totals['invoices_count']) }}</div>
                    <div class="psr-card-sub">{{ number_format($totals['products_count']) }} productos</div>
                </div>
                <div class="psr-card">
                    <div class="psr-card-label">Facturado</div>
                    <div class="psr-card-value psr-num">L {{ number_format($totals['billed_total'], 2) }}</div>
                    <div class="psr-card-sub psr-num">Entró {{ $totals['entered']['label'] }}</div>
                </div>
                <div class="psr-card psr-card-ret">
                    <div class="psr-card-label">Devuelto</div>
                    <div class="psr-card-value psr-num">L {{ number_format($totals['returned_total'], 2) }}</div>
                    <div class="psr-card-sub psr-num">{{ number_format($totals['return_pct'], 1) }}% de lo facturado</div>
                </div>
                <div class="psr-card psr-card-net">
                    <div class="psr-card-label">Venta neta</div>
                    <div class="psr-card-value psr-num">L {{ number_format($totals['net_total'], 2) }}</div>
                    <div class="psr-card-sub psr-num">Se movió {{ $totals['net']['label'] }}</div>
                </div>
            </div>

            @if (count($report['rows']) === 0)
                <div class="psr-empty">
                    <x-filament::icon icon="heroicon-o-inbox" />
                    <span>No hay facturas en este período para las bodegas elegidas.</span>
                </div>
            @else
                <div class="psr-panel" x-data="{ q: '' }">
                    <div class="psr-toolbar">
                        <input
                            type="search"
                            class="psr-search"
                            placeholder="Buscar producto o código…"
                            x-model.debounce.150ms="q"
                            aria-label="Buscar producto o código"
                        >
                        <span class="psr-muted">Cantidades en cajas (cj) y unidades sueltas (u)</span>
                    </div>

                    <div class="psr-tablewrap">
                        <table class="psr-table">
                            <thead>
                                <tr>
                                    <th class="psr-left psr-sticky">Producto</th>
                                    <th class="psr-group-start">Entró</th>
                                    <th>De eso bonif.</th>
                                    <th>Devuelto</th>
                                    <th>Neto movido</th>
                                    <th>% Dev.</th>
                                    <th class="psr-group-start">Facturado</th>
                                    <th>Devuelto L</th>
                                    <th>Neto L</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['rows'] as $row)
                                    <tr
                                        wire:key="psr-row-{{ $row['product_id'] }}"
                                        data-search="{{ mb_strtolower($row['product_id'].' '.$row['description']) }}"
                                        x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())"
                                    >
                                        <td class="psr-left psr-product psr-sticky">
                                            {{ $row['description'] }}
                                            @if ($row['unit_sale'])
                                                <span class="psr-udc">{{ $row['unit_sale'] }}</span>
                                            @endif
                                            <span class="psr-code">{{ $row['product_id'] }}</span>
                                        </td>
                                        <td class="psr-num psr-group-start">{{ $row['entered']['label'] }}</td>
                                        <td class="psr-num {{ $row['bonus_units'] > 0 ? 'psr-bonus' : 'psr-muted' }}">{{ $row['bonus']['label'] }}</td>
                                        <td class="psr-num {{ $row['returned_units'] > 0 ? 'psr-ret' : 'psr-muted' }}">{{ $row['returned']['label'] }}</td>
                                        <td class="psr-num psr-net">{{ $row['net']['label'] }}</td>
                                        <td class="psr-num {{ $row['return_pct'] > 0 ? 'psr-ret' : 'psr-muted' }}">{{ number_format($row['return_pct'], 1) }}%</td>
                                        <td class="psr-num psr-group-start">{{ number_format($row['billed_amount'], 2) }}</td>
                                        <td class="psr-num {{ $row['returned_amount'] > 0 ? 'psr-ret' : 'psr-muted' }}">{{ number_format($row['returned_amount'], 2) }}</td>
                                        <td class="psr-num psr-net">{{ number_format($row['net_amount'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td class="psr-left psr-sticky">Total — {{ number_format($totals['products_count']) }} productos</td>
                                    <td class="psr-num psr-group-start">{{ $totals['entered']['label'] }}</td>
                                    <td class="psr-num psr-bonus">{{ $totals['bonus']['label'] }}</td>
                                    <td class="psr-num psr-ret">{{ $totals['returned']['label'] }}</td>
                                    <td class="psr-num">{{ $totals['net']['label'] }}</td>
                                    <td class="psr-num" title="Porcentaje sobre lo facturado en lempiras">{{ number_format($totals['return_pct'], 1) }}% <span class="psr-muted">en L</span></td>
                                    <td class="psr-num psr-group-start">{{ number_format($totals['billed_total'], 2) }}</td>
                                    <td class="psr-num psr-ret">{{ number_format($totals['returned_total'], 2) }}</td>
                                    <td class="psr-num">{{ number_format($totals['net_total'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <p class="psr-note">
                    <strong>Neto movido = Entró − Devuelto.</strong> La bonificación ya viene incluida en "Entró" (es mercadería que llegó con valor L 0.00) y no se resta.
                    Las devoluciones son las de las facturas del período, aunque se hayan registrado después; las canceladas no cuentan.
                    Los totales en lempiras son los de las facturas (lo que se deposita); la suma de las filas puede diferir unos centavos por el redondeo del proveedor.
                    El % de cada producto es sobre las unidades que entraron; el % del total, sobre los lempiras facturados.
                    El buscador solo filtra la tabla: los totales son siempre del reporte completo.
                </p>
            @endif
        </div>
    @endif
</x-filament-panels::page>
