{{--
    Vista previa de precios del EDT. Los datos los arma
    App\Filament\Resources\Edt\Products\Support\EdtPricePreview; aquí solo se pintan.
    Estilos con prefijo edt-pp y las variables de color de Filament, así se ve
    igual en claro y oscuro sin compilar un tema.
--}}
<style>
    .edt-pp { display: flex; flex-direction: column; gap: 1rem; font-size: .875rem; line-height: 1.25rem; color: var(--gray-950); }
    .dark .edt-pp { color: #fff; }
    .edt-pp-num { font-variant-numeric: tabular-nums; white-space: nowrap; }

    .edt-pp-hero { border-radius: .75rem; padding: 1rem 1.125rem; background: color-mix(in oklab, var(--primary-500) 8%, transparent); border: 1px solid color-mix(in oklab, var(--primary-500) 28%, transparent); }
    .edt-pp-eyebrow { font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--gray-500); }
    .dark .edt-pp-eyebrow { color: var(--gray-400); }
    .edt-pp-hero-values { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .75rem; margin-top: .375rem; }
    .edt-pp-hero-old { font-size: 1.125rem; color: var(--gray-500); text-decoration: line-through; text-decoration-thickness: 1px; }
    .edt-pp-hero-arrow { color: var(--gray-400); font-size: 1.125rem; }
    .edt-pp-hero-new { font-size: 1.875rem; line-height: 2.25rem; font-weight: 700; letter-spacing: -.01em; }
    .edt-pp-hero-foot { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .5rem; margin-top: .625rem; font-size: .8125rem; color: var(--gray-600); }
    .dark .edt-pp-hero-foot { color: var(--gray-300); }
    .edt-pp-hero-foot strong { font-weight: 600; color: var(--gray-950); }
    .dark .edt-pp-hero-foot strong { color: #fff; }

    .edt-pp-chip { display: inline-flex; align-items: center; gap: .25rem; border-radius: 9999px; padding: .125rem .625rem; font-size: .75rem; line-height: 1.25rem; font-weight: 600; }
    .edt-pp-chip-data { background: var(--gray-100); color: var(--gray-700); }
    .dark .edt-pp-chip-data { background: rgb(255 255 255 / .08); color: var(--gray-200); }

    .edt-pp-good { color: var(--success-700); }
    .edt-pp-bad { color: var(--danger-700); }
    .edt-pp-same { color: var(--gray-500); }
    .dark .edt-pp-good { color: var(--success-400); }
    .dark .edt-pp-bad { color: var(--danger-400); }
    .edt-pp-chip.edt-pp-good { background: color-mix(in oklab, var(--success-500) 14%, transparent); }
    .edt-pp-chip.edt-pp-bad { background: color-mix(in oklab, var(--danger-500) 14%, transparent); }
    .edt-pp-chip.edt-pp-same { background: var(--gray-100); }
    .dark .edt-pp-chip.edt-pp-same { background: rgb(255 255 255 / .08); color: var(--gray-300); }

    .edt-pp-table { width: 100%; border-collapse: collapse; }
    .edt-pp-table th { padding: 0 .5rem .5rem; font-size: .75rem; font-weight: 600; color: var(--gray-500); text-align: right; white-space: nowrap; }
    .edt-pp-table th:first-child { text-align: left; padding-left: 0; }
    .edt-pp-table td { padding: .5rem; text-align: right; vertical-align: top; border-top: 1px solid var(--gray-100); }
    .dark .edt-pp-table td { border-color: rgb(255 255 255 / .06); }
    .edt-pp-table td:first-child { text-align: left; padding-left: 0; }
    .edt-pp-table td:last-child, .edt-pp-table th:last-child { padding-right: 0; }
    .edt-pp-table tr.edt-pp-group td { padding-top: 1rem; padding-bottom: .375rem; border-top: 0; font-size: .6875rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--gray-500); }
    .dark .edt-pp-table tr.edt-pp-group td { color: var(--gray-400); }
    .edt-pp-table tr.edt-pp-group:first-child td { padding-top: 0; }
    .edt-pp-sub { display: block; font-size: .75rem; color: var(--gray-500); }
    .edt-pp-old { color: var(--gray-500); }
    .dark .edt-pp-old { color: var(--gray-400); }
    .edt-pp-changed .edt-pp-new { font-weight: 700; }
    .edt-pp-delta { font-size: .8125rem; font-weight: 600; }

    /* Angosto (celular): la diferencia baja debajo del valor nuevo. */
    .edt-pp-tablewrap { container-type: inline-size; }
    .edt-pp-delta-inline { display: none; }
    @container (max-width: 30rem) {
        .edt-pp-col-delta { display: none; }
        .edt-pp-delta-inline { display: block; font-size: .75rem; }
    }

    .edt-pp-alert { margin-top: .75rem; border-radius: .5rem; padding: .5rem .75rem; font-size: .8125rem; font-weight: 600; background: color-mix(in oklab, var(--danger-500) 14%, transparent); color: var(--danger-700); }
    .dark .edt-pp-alert { color: var(--danger-300); }

    .edt-pp-empty { display: flex; align-items: center; gap: .75rem; border: 1px dashed var(--gray-300); border-radius: .75rem; padding: 1.25rem; color: var(--gray-500); }
    .dark .edt-pp-empty { border-color: rgb(255 255 255 / .15); color: var(--gray-400); }
    .edt-pp-empty svg { width: 1.75rem; height: 1.75rem; flex-shrink: 0; }
</style>

@if ($empty)
    <div class="edt-pp-empty">
        <x-filament::icon icon="heroicon-o-calculator" />
        <span>Escribe el precio, las unidades por caja y el ISV, y aquí ves cómo quedan todos los precios.</span>
    </div>
@else
    @php
        $columns = $compare ? 4 : 2;
        $arrows = ['up' => '▲', 'down' => '▼'];
    @endphp

    <div class="edt-pp">
        <div class="edt-pp-hero">
            <div class="edt-pp-eyebrow">Precio al detalle · por unidad · con ISV</div>

            <div class="edt-pp-hero-values">
                @if ($compare && $headline['delta'] !== null)
                    <span class="edt-pp-hero-old edt-pp-num">{{ $headline['old'] }}</span>
                    <span class="edt-pp-hero-arrow" aria-hidden="true">→</span>
                @endif

                <span class="edt-pp-hero-new edt-pp-num">{{ $headline['new'] }}</span>

                @if ($compare)
                    @if ($headline['delta'] !== null)
                        <span class="edt-pp-chip edt-pp-num edt-pp-{{ $headline['tone'] }}">{{ $arrows[$headline['trend']] }} {{ $headline['delta'] }}</span>
                    @else
                        <span class="edt-pp-chip edt-pp-same">Sin cambios</span>
                    @endif
                @endif
            </div>

            <div class="edt-pp-hero-foot">
                <span>
                    Se guarda la lista sin ISV: <strong class="edt-pp-num">{{ $listPrice }}</strong>
                    @if ($previousListPrice)
                        <span class="edt-pp-num">(antes {{ $previousListPrice }})</span>
                    @endif
                </span>

                @foreach ($changes as $change)
                    <span class="edt-pp-chip edt-pp-chip-data">{{ $change }}</span>
                @endforeach
            </div>

            @if ($belowCost)
                <div class="edt-pp-alert">Ojo: con este precio la caja se vende por debajo de lo que cuesta.</div>
            @endif
        </div>

        <div class="edt-pp-tablewrap">
            <table class="edt-pp-table">
                <thead>
                    <tr>
                        <th>Todos con ISV incluido</th>
                        @if ($compare)
                            <th>Actual</th>
                            <th>Nuevo</th>
                            <th class="edt-pp-col-delta">Diferencia</th>
                        @else
                            <th>Precio</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr class="edt-pp-group">
                            <td colspan="{{ $columns }}">{{ $group['title'] }}</td>
                        </tr>

                        @foreach ($group['rows'] as $row)
                            <tr @class(['edt-pp-changed' => $row['delta'] !== null])>
                                <td>
                                    {{ $row['label'] }}
                                    @if ($row['sub'])
                                        <span class="edt-pp-sub">{{ $row['sub'] }}</span>
                                    @endif
                                </td>

                                @if ($compare)
                                    <td class="edt-pp-old edt-pp-num">{{ $row['old'] ?? '—' }}</td>
                                @endif

                                <td class="edt-pp-new edt-pp-num">
                                    {{ $row['new'] }}
                                    @if ($compare && $row['delta'] !== null)
                                        <span class="edt-pp-delta edt-pp-delta-inline edt-pp-{{ $row['tone'] }}">{{ $arrows[$row['trend']] }} {{ $row['delta'] }}</span>
                                    @endif
                                </td>

                                @if ($compare)
                                    <td class="edt-pp-col-delta edt-pp-num">
                                        @if ($row['delta'] !== null)
                                            <span class="edt-pp-delta edt-pp-{{ $row['tone'] }}">{{ $arrows[$row['trend']] }} {{ $row['delta'] }}</span>
                                        @else
                                            <span class="edt-pp-same">—</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
