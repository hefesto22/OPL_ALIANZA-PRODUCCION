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
        /* Cantidades "3,816 cj + 25 u": si no caben, bajan en el "+" sin cortarse. */
        .psr-table td.psr-q { white-space: normal; }
        .psr-nw { white-space: nowrap; }
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

        .psr-recon { border-radius: .75rem; padding: .75rem 1rem; background: color-mix(in oklab, var(--info-500) 8%, transparent); border: 1px solid color-mix(in oklab, var(--info-500) 30%, transparent); border-left: 4px solid var(--info-500); }
        .dark .psr-recon { background: color-mix(in oklab, var(--info-500) 12%, transparent); }
        .psr-recon-title { display: flex; align-items: center; gap: .375rem; margin-bottom: .375rem; font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--info-700); }
        .dark .psr-recon-title { color: var(--info-300); }
        .psr-recon-icon { width: 1rem; height: 1rem; }
        .psr-recon-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
        .psr-recon-table td { padding: .25rem .5rem; vertical-align: top; }
        .psr-recon-table td.psr-num { width: 1%; text-align: right; }
        .psr-recon-op { width: 1rem; font-size: 1rem; font-weight: 700; text-align: center; color: var(--info-700); }
        .dark .psr-recon-op { color: var(--info-300); }
        .psr-recon-out .psr-recon-amount { font-weight: 700; color: var(--danger-700); }
        .dark .psr-recon-out .psr-recon-amount { color: var(--danger-400); }
        .psr-recon-in .psr-recon-amount { font-weight: 700; color: var(--success-700); }
        .dark .psr-recon-in .psr-recon-amount { color: var(--success-400); }
        .psr-recon-result td { border-top: 1px solid color-mix(in oklab, var(--info-500) 35%, transparent); font-weight: 700; }
        .psr-recon-inv { display: block; margin-top: .125rem; font-size: .75rem; color: var(--gray-500); }
        .dark .psr-recon-inv { color: var(--gray-400); }
        .psr-recon-foot { margin-top: .375rem; font-size: .75rem; color: var(--gray-600); }
        .dark .psr-recon-foot { color: var(--gray-400); }

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

        /* ── Traslados entre bodegas ── */
        .psr-section-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: .75rem; padding: 1rem; border-bottom: 1px solid var(--gray-200); }
        .dark .psr-section-head { border-color: rgb(255 255 255 / .1); }
        .psr-section-title { font-size: 1rem; font-weight: 700; }
        .psr-chips { display: flex; flex-wrap: wrap; gap: .5rem; }
        .psr-chip { display: inline-flex; align-items: center; gap: .375rem; border-radius: 9999px; padding: .25rem .75rem; font-size: .8125rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .psr-chip-in { background: color-mix(in oklab, var(--info-500) 12%, transparent); color: var(--info-700); }
        .dark .psr-chip-in { color: var(--info-300); }
        .psr-chip-out { background: color-mix(in oklab, var(--warning-500) 14%, transparent); color: var(--warning-800); }
        .dark .psr-chip-out { color: var(--warning-300); }
        .psr-subtitle { padding: .875rem 1rem .25rem; font-size: .6875rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--gray-500); }
        .dark .psr-subtitle { color: var(--gray-400); }
        .psr-transfer { margin: .5rem 1rem 1rem; border: 1px solid var(--gray-200); border-radius: .75rem; overflow: hidden; }
        .dark .psr-transfer { border-color: rgb(255 255 255 / .1); }
        .psr-transfer-head { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .75rem; padding: .75rem 1rem; background: var(--gray-50); }
        .dark .psr-transfer-head { background: var(--gray-800); }
        .psr-route { display: inline-flex; align-items: center; border-radius: .5rem; padding: .125rem .625rem; font-weight: 700; letter-spacing: .02em; background: var(--primary-500); color: #fff; }
        .psr-transfer-total { margin-left: auto; font-weight: 700; }
        .psr-move { padding: .5rem 1rem; font-size: .8125rem; color: var(--gray-600); border-top: 1px solid var(--gray-100); }
        .dark .psr-move { color: var(--gray-300); border-color: rgb(255 255 255 / .06); }
        .psr-move strong { color: var(--gray-950); }
        .dark .psr-move strong { color: #fff; }
        .psr-reason { font-style: italic; }
        .psr-returns-title { padding: .625rem 1rem .25rem; font-size: .75rem; font-weight: 700; color: var(--gray-500); }
        .psr-returns th { padding: .5rem; white-space: normal; vertical-align: bottom; }
        .psr-returns th:last-child { white-space: nowrap; }
        .psr-returns td { padding: .5rem; font-size: .8125rem; }
        .psr-returns .psr-lines { min-width: 11rem; text-align: left; white-space: normal; }
        .psr-returns .psr-lines div + div { margin-top: .125rem; }
        .psr-badge { display: inline-block; border-radius: .375rem; padding: 0 .375rem; font-size: .6875rem; font-weight: 600; white-space: nowrap; }
        .psr-badge-before { background: color-mix(in oklab, var(--gray-500) 14%, transparent); color: var(--gray-700); }
        .dark .psr-badge-before { color: var(--gray-200); }
        .psr-badge-after { background: color-mix(in oklab, var(--info-500) 14%, transparent); color: var(--info-700); }
        .dark .psr-badge-after { color: var(--info-300); }
        .psr-badge-void { background: color-mix(in oklab, var(--danger-500) 14%, transparent); color: var(--danger-700); }
        .dark .psr-badge-void { color: var(--danger-300); }
        .psr-void td { opacity: .6; }
        .psr-returns .psr-who div + div { margin-top: .25rem; }
        .psr-returns .psr-who { min-width: 14rem; max-width: 24rem; text-align: left; white-space: normal; font-size: .75rem; }
        .psr-summary { margin: 0 1rem 1rem; border: 1px solid var(--gray-200); border-radius: .75rem; overflow: hidden; }
        .dark .psr-summary { border-color: rgb(255 255 255 / .1); }
        .psr-none { padding: 1rem; color: var(--gray-500); }
        .dark .psr-none { color: var(--gray-400); }

        /* Vista previa de impresión (modal) */
        .psr-preview { position: relative; height: min(72vh, 52rem); overflow: hidden; border-radius: .5rem; border: 1px solid var(--gray-200); background: #e5e7eb; }
        .dark .psr-preview { border-color: rgb(255 255 255 / .1); }
        .psr-preview-frame { display: block; width: 100%; height: 100%; border: 0; background: #e5e7eb; }
        .psr-preview-state { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .5rem; font-size: .875rem; font-weight: 500; color: var(--gray-700); background: rgb(255 255 255 / .9); }
        .dark .psr-preview-state { color: var(--gray-200); background: rgb(17 24 39 / .9); }
        .psr-preview-state a { color: var(--primary-600); text-decoration: underline; }
        .psr-preview-spin { width: 1.5rem; height: 1.5rem; border: 2px solid var(--gray-300); border-top-color: var(--primary-500); border-radius: 9999px; animation: psr-spin .7s linear infinite; }
        @keyframes psr-spin { to { transform: rotate(360deg); } }
        .psr-preview-footer { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .75rem; }
    </style>

    {{--
        Imprimir sin salir del reporte: el botón "Imprimir" abre esta vista
        previa (la misma vista de impresión, dentro de un marco) y desde aquí se
        decide si imprimir. "Imprimir" abre el cuadro del navegador: impresora,
        Carta o A4, o "Guardar como PDF". wire:ignore para que Livewire no
        recargue el marco mientras está abierto.
    --}}
    <div
        wire:ignore
        x-data="{
            psrUrl: null,
            psrLoading: false,
            psrFailed: false,
            psrOpen(url) {
                if (! url) {
                    return;
                }

                this.psrFailed = false;
                this.psrLoading = true;
                this.psrUrl = url;
                $dispatch('open-modal', { id: 'psr-print-preview' });
            },
            psrLoaded(frame) {
                const view = frame.contentWindow;

                // El marco vacío también avisa 'load': se ignora.
                if (! this.psrUrl || ! view || view.location.href === 'about:blank') {
                    return;
                }

                const doc = view.document;

                // Si el servidor no devolvió el reporte (permiso, enlace vencido)
                // se avisa y se ofrece abrirlo aparte para ver el mensaje.
                if (! doc.getElementById('toolbar')) {
                    this.psrLoading = false;
                    this.psrFailed = true;

                    return;
                }

                // En la vista previa sobra la barra de arriba: el botón está abajo.
                const style = doc.createElement('style');
                style.textContent = '#toolbar{display:none!important}#report-container{margin-top:0!important}';
                doc.head.appendChild(style);

                this.psrLoading = false;
            },
            psrPrint() {
                const view = this.$refs.psrFrame?.contentWindow;

                if (this.psrLoading || this.psrFailed || ! view) {
                    return;
                }

                view.focus();
                view.print();
            },
            psrReset() {
                this.psrUrl = null;
                this.psrLoading = false;
                this.psrFailed = false;
            },
        }"
        x-on:psr-print.window="psrOpen($event.detail.url)"
        x-on:close-modal.window="if ($event.detail.id === 'psr-print-preview') psrReset()"
    >
        <x-filament::modal
            id="psr-print-preview"
            width="7xl"
            heading="Vista previa del reporte"
            description="Revísalo y, si está bien, dale a Imprimir: ahí eliges la impresora, Carta o A4, o «Guardar como PDF»."
            sticky-footer
        >
            <div class="psr-preview">
                <iframe
                    x-ref="psrFrame"
                    x-bind:src="psrUrl ?? 'about:blank'"
                    x-on:load="psrLoaded($el)"
                    title="Vista previa del reporte"
                    class="psr-preview-frame"
                ></iframe>

                <div class="psr-preview-state" x-show="psrLoading" x-cloak role="status">
                    <span class="psr-preview-spin"></span>
                    Cargando la vista previa…
                </div>

                <div class="psr-preview-state" x-show="psrFailed" x-cloak role="alert">
                    No se pudo cargar la vista previa.
                    <a x-bind:href="psrUrl" target="_blank" rel="noopener">Abrir el reporte aparte</a>
                </div>
            </div>

            <x-slot name="footer">
                <div class="psr-preview-footer">
                    <x-filament::button
                        color="gray"
                        x-on:click="$dispatch('close-modal', { id: 'psr-print-preview' })"
                    >
                        Cerrar
                    </x-filament::button>

                    <x-filament::button
                        icon="heroicon-o-printer"
                        x-on:click="psrPrint()"
                        x-bind:disabled="psrLoading || psrFailed"
                    >
                        Imprimir
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::modal>
    </div>

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
            $recon = $report['transfers']['reconciliation'] ?? null;
            $qty = fn (string $label): string => '<span class="psr-nw">'
                .str_replace(' + ', '</span> <span class="psr-nw">+ ', e($label))
                .'</span>';
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
                    <div class="psr-card-sub psr-num">Vendido {{ $totals['net']['label'] }}</div>
                </div>
            </div>

            @if ($recon !== null && $recon['applies'])
                <div class="psr-recon">
                    <div class="psr-recon-title">
                        <x-filament::icon icon="heroicon-o-arrows-right-left" class="psr-recon-icon" />
                        Facturas que se movieron de bodega
                    </div>
                    <div class="psr-tablewrap">
                        <table class="psr-recon-table">
                            <tr>
                                <td class="psr-recon-op"></td>
                                <td>Jaremar le facturó a {{ $warehouseCodes }}</td>
                                <td class="psr-num">{{ number_format($recon['billed_by_jaremar']['count']) }} facturas</td>
                                <td class="psr-num">L {{ number_format($recon['billed_by_jaremar']['total'], 2) }}</td>
                            </tr>
                            @foreach ($recon['left']['groups'] as $group)
                                <tr class="psr-recon-out">
                                    <td class="psr-recon-op">−</td>
                                    <td>
                                        {{ $group['count'] === 1 ? 'Factura que se envió' : 'Facturas que se enviaron' }} a <strong>{{ $group['from'] }}</strong>
                                        pero después {{ $group['count'] === 1 ? 'se movió' : 'se movieron' }} a <strong>{{ $group['to'] }}</strong>:
                                        ese dinero no cuenta en este reporte, cuenta en {{ $group['to'] }} ({{ $group['quantity'] }})
                                        <span class="psr-recon-inv">{{ implode(' · ', $group['invoices']) }}</span>
                                    </td>
                                    <td class="psr-num">{{ $group['count'] }} {{ $group['count'] === 1 ? 'factura' : 'facturas' }}</td>
                                    <td class="psr-num psr-recon-amount">− L {{ number_format($group['total'], 2) }}</td>
                                </tr>
                            @endforeach
                            @foreach ($recon['arrived']['groups'] as $group)
                                <tr class="psr-recon-in">
                                    <td class="psr-recon-op">+</td>
                                    <td>
                                        {{ $group['count'] === 1 ? 'Factura que se envió' : 'Facturas que se enviaron' }} a <strong>{{ $group['from'] }}</strong>
                                        pero después {{ $group['count'] === 1 ? 'se movió' : 'se movieron' }} a <strong>{{ $group['to'] }}</strong>:
                                        ese dinero sí cuenta en este reporte ({{ $group['quantity'] }})
                                        <span class="psr-recon-inv">{{ implode(' · ', $group['invoices']) }}</span>
                                    </td>
                                    <td class="psr-num">{{ $group['count'] }} {{ $group['count'] === 1 ? 'factura' : 'facturas' }}</td>
                                    <td class="psr-num psr-recon-amount">+ L {{ number_format($group['total'], 2) }}</td>
                                </tr>
                            @endforeach
                            <tr class="psr-recon-result">
                                <td class="psr-recon-op">=</td>
                                <td>Facturado en este reporte (la tarjeta "Facturado")</td>
                                <td class="psr-num">{{ number_format($recon['in_report']['count']) }} facturas</td>
                                <td class="psr-num">L {{ number_format($recon['in_report']['total'], 2) }}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="psr-recon-foot">
                        Lo devuelto de esas facturas también cuenta en la bodega donde quedaron.
                        El detalle de cada una (productos y quién devolvió) está abajo, en "Facturas trasladadas entre bodegas".
                    </div>
                </div>
            @endif

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
                                        <td class="psr-num psr-q psr-group-start">{!! $qty($row['entered']['label']) !!}</td>
                                        <td class="psr-num psr-q {{ $row['bonus_units'] > 0 ? 'psr-bonus' : 'psr-muted' }}">{!! $qty($row['bonus']['label']) !!}</td>
                                        <td class="psr-num psr-q {{ $row['returned_units'] > 0 ? 'psr-ret' : 'psr-muted' }}">{!! $qty($row['returned']['label']) !!}</td>
                                        <td class="psr-num psr-q psr-net">{!! $qty($row['net']['label']) !!}</td>
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
                                    <td class="psr-num psr-q psr-group-start">{!! $qty($totals['entered']['label']) !!}</td>
                                    <td class="psr-num psr-q psr-bonus">{!! $qty($totals['bonus']['label']) !!}</td>
                                    <td class="psr-num psr-q psr-ret">{!! $qty($totals['returned']['label']) !!}</td>
                                    <td class="psr-num psr-q">{!! $qty($totals['net']['label']) !!}</td>
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

            @php
                $transfers = $report['transfers'];
                $transferGroups = [
                    ['key' => 'received', 'label' => 'Llegaron por traslado (cuentan en este reporte)'],
                    ['key' => 'sent', 'label' => 'Salieron a otra bodega (no cuentan en este reporte)'],
                ];
            @endphp

            <div class="psr-panel">
                <div class="psr-section-head">
                    <div>
                        <div class="psr-section-title">Facturas trasladadas entre bodegas</div>
                        <div class="psr-muted">Una factura trasladada cuenta como venta de la bodega donde quedó.</div>
                    </div>
                    <div class="psr-chips">
                        <span class="psr-chip psr-chip-in">Llegaron: {{ $transfers['received_count'] }} · L {{ number_format($transfers['received_total'], 2) }}</span>
                        <span class="psr-chip psr-chip-out">Salieron: {{ $transfers['sent_count'] }} · L {{ number_format($transfers['sent_total'], 2) }}</span>
                    </div>
                </div>

                @if ($transfers['received_count'] === 0 && $transfers['sent_count'] === 0)
                    <div class="psr-none">Ninguna factura de este período cambió de bodega.</div>
                @endif

                @foreach ($transferGroups as $group)
                    @if (count($transfers[$group['key']]) > 0)
                        <div class="psr-subtitle">{{ $group['label'] }}</div>

                        @foreach ($transfers[$group['key']] as $invoice)
                            <div class="psr-transfer" wire:key="psr-transfer-{{ $group['key'] }}-{{ $invoice['id'] }}">
                                <div class="psr-transfer-head">
                                    <span class="psr-route">{{ $invoice['chain'] }}</span>
                                    <strong>{{ $invoice['invoice_number'] }}</strong>
                                    <span class="psr-muted">{{ $invoice['client'] }} · {{ $invoice['invoice_date'] }} · Manifiesto {{ $invoice['manifest_number'] }}</span>
                                    <span class="psr-transfer-total psr-num">
                                        L {{ number_format($invoice['total'], 2) }}
                                        @if ($invoice['returned_total'] > 0)
                                            <span class="psr-ret">(devuelto L {{ number_format($invoice['returned_total'], 2) }})</span>
                                        @endif
                                    </span>
                                </div>

                                @foreach ($invoice['moves'] as $move)
                                    <div class="psr-move">
                                        Trasladada de <strong>{{ $move['from'] }}</strong> a <strong>{{ $move['to'] }}</strong>
                                        el {{ $move['at'] }} por <strong>{{ $move['by'] }}</strong> —
                                        <span class="psr-reason">“{{ $move['reason'] }}”</span>
                                    </div>
                                @endforeach

                                <div class="psr-returns-title">Productos de esta factura</div>
                                <div class="psr-tablewrap">
                                    <table class="psr-table psr-returns">
                                        <thead>
                                            <tr>
                                                <th class="psr-left">Producto</th>
                                                <th>Cantidad</th>
                                                <th>Devuelto</th>
                                                <th class="psr-left">Quién devolvió</th>
                                                <th>Neto</th>
                                                <th>Monto L</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($invoice['products'] as $product)
                                                <tr>
                                                    <td class="psr-left">
                                                        {{ $product['description'] }}
                                                        @if ($product['bonus'])
                                                            <span class="psr-badge psr-badge-after">Bonificación</span>
                                                        @endif
                                                        <span class="psr-code">{{ $product['product_id'] }}</span>
                                                    </td>
                                                    <td class="psr-num psr-q">{!! $qty($product['quantity']) !!}</td>
                                                    <td class="psr-num psr-q {{ $product['returned_units'] > 0 ? 'psr-ret' : 'psr-muted' }}">{!! $qty($product['returned']) !!}</td>
                                                    <td class="psr-who">
                                                        @forelse ($product['returned_by'] as $who)
                                                            <div>
                                                                <strong>{{ $who['user'] }}</strong> ({{ $who['user_warehouses'] }}) — {{ $who['quantity'] }}
                                                                <span class="psr-muted">· en {{ $who['registered_in'] }} · {{ $who['registered_at'] }} · {{ mb_strtolower($who['timing']) }}</span>
                                                            </div>
                                                        @empty
                                                            <span class="psr-muted">—</span>
                                                        @endforelse
                                                    </td>
                                                    <td class="psr-num psr-q psr-net">{!! $qty($product['net']) !!}</td>
                                                    <td class="psr-num">{{ number_format($product['amount'], 2) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                @if (count($invoice['returns']) === 0)
                                    <div class="psr-move">Sin devoluciones.</div>
                                @else
                                    <div class="psr-returns-title">Devoluciones de esta factura</div>
                                    <div class="psr-tablewrap">
                                        <table class="psr-table psr-returns">
                                            <thead>
                                                <tr>
                                                    <th class="psr-left">Registrada</th>
                                                    <th class="psr-left">Registró</th>
                                                    <th class="psr-left">Bodega del usuario</th>
                                                    <th class="psr-left">La factura estaba en</th>
                                                    <th class="psr-left">Momento</th>
                                                    <th class="psr-left">Tipo / estado</th>
                                                    <th class="psr-left">Productos</th>
                                                    <th>Monto L</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($invoice['returns'] as $return)
                                                    <tr @class(['psr-void' => ! $return['counts']])>
                                                        <td class="psr-left psr-num">{{ $return['registered_at'] }}</td>
                                                        <td class="psr-left">{{ $return['user'] }}</td>
                                                        <td class="psr-left">{{ $return['user_warehouses'] }}</td>
                                                        <td class="psr-left"><strong>{{ $return['registered_in'] }}</strong></td>
                                                        <td class="psr-left">
                                                            <span @class(['psr-badge', 'psr-badge-before' => str_starts_with($return['timing'], 'Antes'), 'psr-badge-after' => ! str_starts_with($return['timing'], 'Antes')])>{{ $return['timing'] }}</span>
                                                        </td>
                                                        <td class="psr-left">
                                                            {{ $return['type'] }} ·
                                                            <span @class(['psr-badge psr-badge-void' => ! $return['counts']])>{{ $return['status'] }}</span>
                                                        </td>
                                                        <td class="psr-lines">
                                                            @foreach ($return['lines'] as $line)
                                                                <div>{{ $line['description'] }} — <span class="psr-num">{{ $line['quantity'] }}</span></div>
                                                            @endforeach
                                                        </td>
                                                        <td class="psr-num">{{ number_format($return['total'], 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif
                @endforeach

                @if (count($transfers['products_summary']) > 0)
                    <div class="psr-subtitle">Resumen de productos trasladados</div>
                    <div class="psr-summary psr-tablewrap">
                        <table class="psr-table psr-returns">
                            <thead>
                                <tr>
                                    <th class="psr-left">Producto</th>
                                    <th>Llegaron</th>
                                    <th>L llegaron</th>
                                    <th>Salieron</th>
                                    <th>L salieron</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transfers['products_summary'] as $product)
                                    <tr>
                                        <td class="psr-left">
                                            {{ $product['description'] }}
                                            <span class="psr-code">{{ $product['product_id'] }}</span>
                                        </td>
                                        <td class="psr-num psr-q">{!! $qty($product['received']['label']) !!}</td>
                                        <td class="psr-num">{{ number_format($product['received_amount'], 2) }}</td>
                                        <td class="psr-num psr-q">{!! $qty($product['sent']['label']) !!}</td>
                                        <td class="psr-num">{{ number_format($product['sent_amount'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td class="psr-left">Total — {{ count($transfers['products_summary']) }} productos</td>
                                    <td class="psr-num psr-q">{!! $qty($transfers['products_totals']['received']['label']) !!}</td>
                                    <td class="psr-num">{{ number_format($transfers['products_totals']['received_amount'], 2) }}</td>
                                    <td class="psr-num psr-q">{!! $qty($transfers['products_totals']['sent']['label']) !!}</td>
                                    <td class="psr-num">{{ number_format($transfers['products_totals']['sent_amount'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
