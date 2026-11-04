{{--
    Configuración → Devoluciones.
    Los días de ventana son el formulario de Filament ({{ $this->form }});
    cuándo ve Jaremar las devoluciones son tres tarjetas propias, enlazadas a
    $publication_choice y $publication_delay_days de ReturnSettingsPage.
    Estilos con prefijo rps- y las variables de color de Filament: se ve igual
    en claro y oscuro sin compilar un tema (mismo patrón que el reporte de ventas).
--}}
<x-filament-panels::page>
    <style>
        .rps { display: flex; flex-direction: column; gap: 1.5rem; }

        /* ── Así funciona hoy ─────────────────────────────────────── */
        .rps-now { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: .75rem; }
        .rps-now-item { display: flex; gap: .75rem; align-items: flex-start; border-radius: .875rem; padding: .875rem 1rem; background: #fff; border: 1px solid var(--gray-200); }
        .dark .rps-now-item { background: rgb(255 255 255 / .04); border-color: rgb(255 255 255 / .1); }
        .rps-now-icon { flex: none; display: inline-flex; width: 2.25rem; height: 2.25rem; align-items: center; justify-content: center; border-radius: .625rem; background: color-mix(in oklab, var(--primary-500) 12%, transparent); color: var(--primary-600); }
        .dark .rps-now-icon { color: var(--primary-400); }
        .rps-now-icon svg { width: 1.25rem; height: 1.25rem; }
        .rps-now-label { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--gray-500); }
        .dark .rps-now-label { color: var(--gray-400); }
        .rps-now-value { margin-top: .125rem; font-size: 1rem; line-height: 1.375rem; font-weight: 700; color: var(--gray-950); }
        .dark .rps-now-value { color: #fff; }
        .rps-now-sub { margin-top: .125rem; font-size: .75rem; color: var(--gray-500); }
        .dark .rps-now-sub { color: var(--gray-400); }

        /* ── Tarjetas ─────────────────────────────────────────────── */
        .rps-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: .875rem; }
        .rps-card { position: relative; display: flex; flex-direction: column; gap: .5rem; padding: 1rem 1.125rem 1.125rem; border-radius: .875rem; border: 1.5px solid var(--gray-200); background: #fff; cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease; }
        .dark .rps-card { background: rgb(255 255 255 / .03); border-color: rgb(255 255 255 / .1); }
        .rps-card:hover { border-color: var(--gray-300); }
        .dark .rps-card:hover { border-color: rgb(255 255 255 / .22); }
        .rps-card:focus-within { outline: 2px solid var(--primary-500); outline-offset: 2px; }
        .rps-card-on, .rps-card-on:hover { border-color: var(--primary-500); background: color-mix(in oklab, var(--primary-500) 6%, #fff); box-shadow: 0 0 0 4px color-mix(in oklab, var(--primary-500) 16%, transparent); }
        .dark .rps-card-on, .dark .rps-card-on:hover { border-color: var(--primary-400); background: color-mix(in oklab, var(--primary-500) 12%, transparent); }
        .rps-radio { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }

        .rps-tone-live { --rps-tone: var(--success-600); }
        .rps-tone-wait { --rps-tone: var(--info-600); }
        .rps-tone-pack { --rps-tone: var(--gray-600); }
        .dark .rps-tone-live { --rps-tone: var(--success-400); }
        .dark .rps-tone-wait { --rps-tone: var(--info-400); }
        .dark .rps-tone-pack { --rps-tone: var(--gray-300); }

        .rps-card-top { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
        .rps-card-icon { display: inline-flex; width: 2.5rem; height: 2.5rem; align-items: center; justify-content: center; border-radius: .75rem; background: color-mix(in oklab, var(--rps-tone) 14%, transparent); color: var(--rps-tone); }
        .rps-card-icon svg { width: 1.375rem; height: 1.375rem; }
        .rps-check { display: inline-flex; width: 1.375rem; height: 1.375rem; align-items: center; justify-content: center; border-radius: 999px; border: 1.5px solid var(--gray-300); color: transparent; transition: all .15s ease; }
        .dark .rps-check { border-color: rgb(255 255 255 / .25); }
        .rps-check svg { width: .875rem; height: .875rem; }
        .rps-card-on .rps-check { background: var(--primary-500); border-color: var(--primary-500); color: #fff; }

        .rps-chip { display: inline-flex; align-items: center; gap: .375rem; align-self: flex-start; font-size: .6875rem; font-weight: 600; letter-spacing: .02em; padding: .1875rem .5625rem; border-radius: 999px; background: color-mix(in oklab, var(--rps-tone) 14%, transparent); color: var(--rps-tone); }
        .rps-dot { width: .4375rem; height: .4375rem; border-radius: 999px; background: currentColor; animation: rps-pulse 1.6s ease-out infinite; }
        @keyframes rps-pulse {
            0% { box-shadow: 0 0 0 0 color-mix(in oklab, var(--success-500) 55%, transparent); }
            70% { box-shadow: 0 0 0 .4375rem transparent; }
            100% { box-shadow: 0 0 0 0 transparent; }
        }
        @media (prefers-reduced-motion: reduce) { .rps-dot { animation: none; } }

        .rps-card-title { font-size: 1rem; line-height: 1.375rem; font-weight: 700; color: var(--gray-950); }
        .dark .rps-card-title { color: #fff; }
        .rps-card-desc { font-size: .8125rem; line-height: 1.25rem; color: var(--gray-600); }
        .dark .rps-card-desc { color: var(--gray-300); }
        .rps-card-note { margin-top: auto; padding-top: .5rem; display: flex; gap: .375rem; align-items: flex-start; font-size: .75rem; line-height: 1.125rem; color: var(--gray-500); border-top: 1px dashed var(--gray-200); }
        .dark .rps-card-note { color: var(--gray-400); border-top-color: rgb(255 255 255 / .1); }
        .rps-card-note svg { flex: none; width: 1rem; height: 1rem; margin-top: .0625rem; }

        /* ── Días después ─────────────────────────────────────────── */
        .rps-delay { margin-top: 1rem; display: flex; flex-wrap: wrap; align-items: flex-end; gap: .75rem 1.25rem; padding: 1rem 1.125rem; border-radius: .875rem; background: var(--gray-50); border: 1px dashed var(--gray-300); }
        .dark .rps-delay { background: rgb(255 255 255 / .03); border-color: rgb(255 255 255 / .15); }
        .rps-delay-label { display: block; margin-bottom: .375rem; font-size: .875rem; font-weight: 500; color: var(--gray-950); }
        .dark .rps-delay-label { color: #fff; }
        .rps-delay-field { width: 10rem; }
        .rps-quick { display: flex; flex-wrap: wrap; gap: .5rem; }
        .rps-pill { font-size: .8125rem; font-weight: 500; padding: .4375rem .875rem; border-radius: 999px; border: 1px solid var(--gray-300); background: #fff; color: var(--gray-700); cursor: pointer; transition: all .15s ease; }
        .dark .rps-pill { background: rgb(255 255 255 / .05); border-color: rgb(255 255 255 / .15); color: var(--gray-200); }
        .rps-pill:hover { border-color: var(--primary-500); }
        .rps-pill-on { background: var(--primary-500); border-color: var(--primary-500); color: #fff; }
        .dark .rps-pill-on { background: var(--primary-500); color: #fff; }

        /* ── Ejemplo y avisos ─────────────────────────────────────── */
        .rps-preview { margin-top: 1rem; display: flex; gap: .625rem; align-items: flex-start; font-size: .875rem; line-height: 1.375rem; padding: .875rem 1rem; border-radius: .875rem; background: color-mix(in oklab, var(--primary-500) 8%, transparent); border: 1px solid color-mix(in oklab, var(--primary-500) 22%, transparent); color: var(--gray-800); }
        .dark .rps-preview { color: var(--gray-100); }
        .rps-preview svg { flex: none; width: 1.25rem; height: 1.25rem; margin-top: .0625rem; color: var(--primary-600); }
        .dark .rps-preview svg { color: var(--primary-400); }
        .rps-preview strong { font-weight: 600; }
        .rps-warn { margin-top: .75rem; display: flex; gap: .625rem; align-items: flex-start; font-size: .8125rem; line-height: 1.25rem; padding: .75rem 1rem; border-radius: .875rem; background: color-mix(in oklab, var(--warning-500) 12%, transparent); border: 1px solid color-mix(in oklab, var(--warning-500) 35%, transparent); color: var(--warning-800); }
        .dark .rps-warn { color: var(--warning-300); }
        .rps-warn svg { flex: none; width: 1.125rem; height: 1.125rem; margin-top: .0625rem; }
        .rps-error { margin-top: .5rem; font-size: .8125rem; color: var(--danger-600); }
        .dark .rps-error { color: var(--danger-400); }

        .rps-actions { display: flex; justify-content: flex-end; }
    </style>

    @php
        $summary = $this->currentSummary();
        $choices = $this::publicationChoices();
        $preview = $this->publicationPreview();
        $immediate = \App\Filament\Pages\ReturnSettingsPage::CHOICE_IMMEDIATE;
        $afterDays = \App\Filament\Pages\ReturnSettingsPage::CHOICE_AFTER_DAYS;
        $maxDelayDays = \App\Models\ReturnSetting::MAX_DELAY_DAYS;
        $delayPills = collect([1, 2, 3, 7])
            ->map(fn (int $days): array => [
                'days' => $days,
                'label' => $days === 1 ? '1 día' : $days.' días',
                'on' => (string) $publication_delay_days === (string) $days,
            ])
            ->all();
    @endphp

    <div class="rps">
        {{-- ── Así funciona hoy (lo guardado) ─────────────────────────── --}}
        <div class="rps-now" aria-label="Así funciona hoy">
            <div class="rps-now-item">
                <span class="rps-now-icon"><x-filament::icon icon="heroicon-o-calendar-days" /></span>
                <div>
                    <div class="rps-now-label">Días para registrar</div>
                    <div class="rps-now-value">{{ $summary['window'] }}</div>
                    <div class="rps-now-sub">por manifiesto</div>
                </div>
            </div>
            <div class="rps-now-item">
                <span class="rps-now-icon"><x-filament::icon icon="heroicon-o-eye" /></span>
                <div>
                    <div class="rps-now-label">Jaremar las ve</div>
                    <div class="rps-now-value">{{ $summary['publication'] }}</div>
                    <div class="rps-now-sub">así funciona hoy</div>
                </div>
            </div>
            <div class="rps-now-item">
                <span class="rps-now-icon"><x-filament::icon icon="heroicon-o-user-circle" /></span>
                <div>
                    <div class="rps-now-label">Último cambio</div>
                    <div class="rps-now-value">{{ $summary['updated_by'] }}</div>
                    <div class="rps-now-sub">{{ $summary['updated_at'] }}</div>
                </div>
            </div>
        </div>

        <form wire:submit="save" class="rps">
            {{ $this->form }}

            <x-filament::section
                icon="heroicon-o-eye"
                heading="Cuándo ve Jaremar las devoluciones"
                description="Aplica a las devoluciones que se registren desde que guardes; las que ya están registradas no cambian."
            >
                <div class="rps-cards" role="radiogroup" aria-label="Cuándo ve Jaremar las devoluciones">
                    @foreach ($choices as $key => $choice)
                        <label @class(['rps-card', 'rps-tone-'.$choice['tone'], 'rps-card-on' => $publication_choice === $key]) wire:key="rps-choice-{{ $key }}">
                            <input
                                type="radio"
                                class="rps-radio"
                                name="publication_choice"
                                value="{{ $key }}"
                                wire:model.live="publication_choice"
                            />

                            <span class="rps-card-top">
                                <span class="rps-card-icon"><x-filament::icon :icon="$choice['icon']" /></span>
                                <span class="rps-check"><x-filament::icon icon="heroicon-m-check" /></span>
                            </span>

                            <span class="rps-chip">
                                @if ($key === $immediate)
                                    <span class="rps-dot"></span>
                                @endif
                                {{ $choice['chip'] }}
                            </span>

                            <span class="rps-card-title">{{ $choice['title'] }}</span>
                            <span class="rps-card-desc">{{ $choice['description'] }}</span>

                            <span class="rps-card-note">
                                <x-filament::icon icon="heroicon-o-information-circle" />
                                <span>{{ $choice['note'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                @error('publication_choice')
                    <p class="rps-error">{{ $message }}</p>
                @enderror

                @if ($publication_choice === $afterDays)
                    <div class="rps-delay">
                        <div>
                            <label for="rps-delay-input" class="rps-delay-label">¿Cuántos días después?</label>
                            <x-filament::input.wrapper
                                class="rps-delay-field"
                                suffix="días"
                                :valid="! $errors->has('publication_delay_days')"
                            >
                                <x-filament::input
                                    id="rps-delay-input"
                                    type="number"
                                    min="1"
                                    max="{{ $maxDelayDays }}"
                                    step="1"
                                    wire:model.live.debounce.400ms="publication_delay_days"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        <div class="rps-quick" role="group" aria-label="Días rápidos">
                            @foreach ($delayPills as $pill)
                                <button
                                    type="button"
                                    wire:click="$set('publication_delay_days', {{ $pill['days'] }})"
                                    @class(['rps-pill', 'rps-pill-on' => $pill['on']])
                                >
                                    {{ $pill['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @error('publication_delay_days')
                        <p class="rps-error">{{ $message }}</p>
                    @enderror
                @endif

                <div class="rps-preview">
                    <x-filament::icon icon="heroicon-o-light-bulb" />
                    <span><strong>Ejemplo:</strong> {{ $preview }}</span>
                </div>

                @if ($publication_choice === $immediate)
                    <div class="rps-warn">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                        <span>Con «Al momento» la bodega ya no puede corregir ni cancelar una devolución después de guardarla, porque Jaremar ya la tiene. Que la revisen bien antes de guardar.</span>
                    </div>
                @endif
            </x-filament::section>

            <div class="rps-actions">
                <x-filament::button type="submit" icon="heroicon-o-check" wire:target="save">
                    Guardar cambios
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
