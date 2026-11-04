<?php

namespace App\Filament\Pages;

use App\Enums\ReturnPublicationMode;
use App\Models\ReturnSetting;
use App\Models\User;
use App\Services\ReturnSettingsService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Configuración → Devoluciones: cuántos días tiene cada manifiesto para
 * registrar devoluciones y cuándo las ve Jaremar en GET v1/devoluciones/listar.
 *
 * Los días de ventana van en el formulario de Filament; cuándo las ve
 * Jaremar se elige en tres tarjetas (vista return-settings):
 *
 *   Al momento            → after_registration con 0 días (en vivo)
 *   Después de unos días  → after_registration con 1 a 30 días
 *   Al cerrar la ventana  → window (paquete completo, lo de siempre)
 *
 * Cada entorno (pruebas, producción) guarda lo suyo en return_settings.
 * Acceso igual que "Proveedor (Jaremar)": super_admin y admin.
 */
class ReturnSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    public const CHOICE_IMMEDIATE = 'immediate';

    public const CHOICE_AFTER_DAYS = 'after_days';

    public const CHOICE_WINDOW = 'window';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected string $view = 'filament.pages.return-settings';

    protected static ?string $slug = 'configuracion/devoluciones';

    protected static ?string $navigationLabel = 'Devoluciones';

    protected static ?string $title = 'Configuración de Devoluciones';

    protected static ?int $navigationSort = 4;

    public ?int $window_business_days = null;

    public bool $apply_to_open = false;

    /** Tarjeta elegida (CHOICE_*). */
    public string $publication_choice = self::CHOICE_WINDOW;

    /** Lo que se escribe en "¿Cuántos días después?" (texto mientras se escribe). */
    public int|string|null $publication_delay_days = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Configuración';
    }

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Estos ajustes son solo de '.config('app.name').': cada entorno guarda los suyos.';
    }

    /**
     * Las tres formas en que Jaremar puede recibir las devoluciones, en el
     * orden de las tarjetas.
     *
     * @return array<string, array{title: string, chip: string, icon: string, tone: string, description: string, note: string}>
     */
    public static function publicationChoices(): array
    {
        return [
            self::CHOICE_IMMEDIATE => [
                'title' => 'Al momento',
                'chip' => 'En vivo',
                'icon' => 'heroicon-o-bolt',
                'tone' => 'live',
                'description' => 'Jaremar ve la devolución en cuanto la bodega la guarda.',
                'note' => 'Ya no se puede corregir ni cancelar después de guardarla.',
            ],
            self::CHOICE_AFTER_DAYS => [
                'title' => 'Después de unos días',
                'chip' => 'Con margen',
                'icon' => 'heroicon-o-clock',
                'tone' => 'wait',
                'description' => 'Jaremar la ve cuando pasan los días que elijas desde que se registró.',
                'note' => 'Mientras tanto se puede cancelar; corregir, solo el mismo día.',
            ],
            self::CHOICE_WINDOW => [
                'title' => 'Al cerrar la ventana del manifiesto',
                'chip' => 'Paquete completo',
                'icon' => 'heroicon-o-archive-box',
                'tone' => 'pack',
                'description' => 'Jaremar recibe todas las devoluciones del manifiesto juntas cuando se acaban los días para registrar.',
                'note' => 'Es como funcionaba hasta ahora.',
            ],
        ];
    }

    public function mount(): void
    {
        $settings = ReturnSetting::current();

        $this->form->fill([
            'window_business_days' => $settings->windowBusinessDays(),
            'apply_to_open' => false,
        ]);

        $this->publication_choice = self::choiceFor($settings);
        $this->publication_delay_days = max(1, (int) $settings->publication_delay_days);
    }

    public function form(Schema $schema): Schema
    {
        $openManifests = app(ReturnSettingsService::class)->openManifestsCount();

        return $schema->components([
            Section::make('Días para registrar devoluciones')
                ->icon('heroicon-o-calendar-days')
                ->description('Cuántos días tiene cada manifiesto para registrar, corregir o cancelar devoluciones.')
                ->columns(['md' => 3])
                ->schema([
                    TextInput::make('window_business_days')
                        ->label('Días hábiles por manifiesto')
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(ReturnSetting::MAX_WINDOW_DAYS)
                        ->suffix('días')
                        ->live(onBlur: true)
                        ->helperText('De lunes a sábado; el domingo no cuenta.')
                        ->columnSpan(['md' => 1]),

                    Toggle::make('apply_to_open')
                        ->label('Aplicar también a los manifiestos que siguen abiertos')
                        ->helperText(
                            'Hoy hay '.$openManifests.' '.($openManifests === 1 ? 'manifiesto abierto' : 'manifiestos abiertos').'. '.
                            'Si no lo marcas, los días nuevos solo aplican a los manifiestos que lleguen desde ahora. '.
                            'Si bajas los días, los abiertos que ya pasen el nuevo límite se cierran al guardar.'
                        )
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Al pasar a "Después de unos días" sin un número válido, arranca en 1.
     */
    public function updatedPublicationChoice(): void
    {
        if ($this->publication_choice === self::CHOICE_AFTER_DAYS && (int) $this->publication_delay_days < 1) {
            $this->publication_delay_days = 1;
        }
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Validator::make(
            [
                'publication_choice' => $this->publication_choice,
                'publication_delay_days' => $this->publication_delay_days,
            ],
            [
                'publication_choice' => ['required', Rule::in(array_keys(self::publicationChoices()))],
                'publication_delay_days' => [
                    'exclude_unless:publication_choice,'.self::CHOICE_AFTER_DAYS,
                    'required', 'integer', 'min:1', 'max:'.ReturnSetting::MAX_DELAY_DAYS,
                ],
            ],
            [
                'publication_choice.required' => 'Elige cuándo ve Jaremar las devoluciones.',
                'publication_choice.in' => 'Elige una de las tres opciones.',
                'publication_delay_days.required' => 'Escribe cuántos días.',
                'publication_delay_days.integer' => 'Escribe un número entero de días.',
                'publication_delay_days.min' => 'Pon al menos 1 día. Para que Jaremar la vea en el momento, elige «Al momento».',
                'publication_delay_days.max' => 'Pon como máximo '.ReturnSetting::MAX_DELAY_DAYS.' días.',
            ],
        )->validate();

        $current = ReturnSetting::current();

        [$mode, $delayDays] = match ($this->publication_choice) {
            self::CHOICE_IMMEDIATE => [ReturnPublicationMode::AfterRegistration, 0],
            self::CHOICE_AFTER_DAYS => [ReturnPublicationMode::AfterRegistration, (int) $this->publication_delay_days],
            // Al cerrar la ventana los días no se usan: se conserva el último elegido.
            default => [ReturnPublicationMode::Window, max(1, (int) $current->publication_delay_days)],
        };

        /** @var User $user */
        $user = Auth::user();

        $updated = app(ReturnSettingsService::class)->update(
            windowBusinessDays: (int) $data['window_business_days'],
            mode: $mode,
            delayDays: $delayDays,
            applyToOpenManifests: (bool) ($data['apply_to_open'] ?? false),
            user: $user,
        );

        $this->apply_to_open = false;

        Notification::make()
            ->title('Configuración de devoluciones guardada.')
            ->body($updated > 0 ? "Se actualizó el cierre de {$updated} manifiestos abiertos." : null)
            ->success()
            ->send();
    }

    /**
     * Ejemplo con la hora de ahora de lo que pasaría con la opción elegida.
     */
    public function publicationPreview(): string
    {
        $now = now()->locale('es');
        $hora = $now->translatedFormat('g:i a');

        if ($this->publication_choice === self::CHOICE_IMMEDIATE) {
            return "Si la bodega registra una devolución hoy a las {$hora}, Jaremar la ve a las {$hora}, en el mismo momento.";
        }

        if ($this->publication_choice === self::CHOICE_AFTER_DAYS) {
            $days = filter_var($this->publication_delay_days, FILTER_VALIDATE_INT);

            if ($days === false || $days < 1 || $days > ReturnSetting::MAX_DELAY_DAYS) {
                return 'Escribe de 1 a '.ReturnSetting::MAX_DELAY_DAYS.' días para ver el ejemplo.';
            }

            $cuando = $now->copy()->addDays($days)->translatedFormat('l j \d\e F \a \l\a\s g:i a');

            return "Si la bodega registra una devolución hoy a las {$hora}, Jaremar la ve el {$cuando}.";
        }

        $windowDays = (int) ($this->window_business_days ?: ReturnSetting::current()->windowBusinessDays());

        return "Jaremar recibe todas las devoluciones de un manifiesto juntas cuando se acaban sus {$windowDays} días hábiles para registrar.";
    }

    /**
     * Cómo quedó guardado (no lo que se está editando), para el resumen de arriba.
     *
     * @return array{window: string, publication: string, updated_by: string, updated_at: string}
     */
    public function currentSummary(): array
    {
        $settings = ReturnSetting::current()->loadMissing('updatedBy:id,name');
        $windowDays = $settings->windowBusinessDays();

        $publication = match (self::choiceFor($settings)) {
            self::CHOICE_IMMEDIATE => 'Al momento (en vivo)',
            self::CHOICE_AFTER_DAYS => $settings->publication_delay_days === 1
                ? '1 día después de registrarla'
                : "{$settings->publication_delay_days} días después de registrarla",
            default => 'Al cerrar la ventana del manifiesto',
        };

        $changed = $settings->updatedBy !== null && $settings->updated_at !== null;

        return [
            'window' => $windowDays === 1 ? '1 día hábil' : "{$windowDays} días hábiles",
            'publication' => $publication,
            'updated_by' => $changed ? $settings->updatedBy->name : 'Nadie todavía',
            'updated_at' => $changed
                ? 'el '.$settings->updated_at->format('d/m/Y').' a las '.$settings->updated_at->format('h:i a')
                : 'Sin cambios desde que se instaló',
        ];
    }

    private static function choiceFor(ReturnSetting $settings): string
    {
        return match ($settings->publicationDelayDays()) {
            null => self::CHOICE_WINDOW,
            0 => self::CHOICE_IMMEDIATE,
            default => self::CHOICE_AFTER_DAYS,
        };
    }
}
