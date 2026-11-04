<?php

namespace App\Filament\Pages;

use App\Enums\ReturnPublicationMode;
use App\Models\ReturnSetting;
use App\Models\User;
use App\Services\ReturnSettingsService;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración → Devoluciones: cuántos días tiene cada manifiesto para
 * registrar devoluciones y cuándo las ve Jaremar en GET v1/devoluciones/listar.
 *
 * Cada entorno (pruebas, producción) guarda la suya en return_settings.
 * Acceso igual que "Proveedor (Jaremar)": super_admin y admin.
 */
class ReturnSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected string $view = 'filament.pages.return-settings';

    protected static ?string $slug = 'configuracion/devoluciones';

    protected static ?string $navigationLabel = 'Devoluciones';

    protected static ?string $title = 'Configuración de Devoluciones';

    protected static ?int $navigationSort = 4;

    public ?int $window_business_days = null;

    public bool $apply_to_open = false;

    public ?string $publication_mode = null;

    public ?int $publication_delay_days = null;

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

    public function mount(): void
    {
        $settings = ReturnSetting::current();

        $this->form->fill([
            'window_business_days' => $settings->windowBusinessDays(),
            'apply_to_open' => false,
            'publication_mode' => $settings->publication_mode->value,
            'publication_delay_days' => (int) $settings->publication_delay_days,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $openManifests = app(ReturnSettingsService::class)->openManifestsCount();

        return $schema->components([
            Section::make('Días para registrar devoluciones')
                ->icon('heroicon-o-calendar-days')
                ->description('Cuántos días tiene cada manifiesto para registrar, corregir o cancelar devoluciones.')
                ->schema([
                    TextInput::make('window_business_days')
                        ->label('Días hábiles por manifiesto')
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(ReturnSetting::MAX_WINDOW_DAYS)
                        ->suffix('días')
                        ->helperText('De lunes a sábado (el domingo no cuenta), contando el día que llega el manifiesto. Al terminar, ya no se registra, corrige ni cancela ninguna devolución de ese manifiesto.'),

                    Toggle::make('apply_to_open')
                        ->label('Aplicar también a los manifiestos que siguen abiertos')
                        ->helperText(
                            'Hoy hay '.$openManifests.' '.($openManifests === 1 ? 'manifiesto abierto' : 'manifiestos abiertos').'. '.
                            'Si no lo marcas, los días nuevos solo aplican a los manifiestos que lleguen desde ahora. '.
                            'Si bajas los días, los abiertos que ya pasen el nuevo límite se cierran al guardar.'
                        ),
                ]),

            Section::make('Cuándo ve Jaremar las devoluciones')
                ->icon('heroicon-o-eye')
                ->description('Aplica a las devoluciones que se registren desde ahora; las que ya están registradas no cambian.')
                ->schema([
                    Radio::make('publication_mode')
                        ->hiddenLabel()
                        ->options(ReturnPublicationMode::class)
                        ->required()
                        ->live(),

                    TextInput::make('publication_delay_days')
                        ->label('Días después de registrarla')
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(ReturnSetting::MAX_DELAY_DAYS)
                        ->suffix('días')
                        ->live(onBlur: true)
                        ->visible(fn (Get $get): bool => self::isAfterRegistration($get('publication_mode')))
                        ->helperText(fn (Get $get): string => (int) $get('publication_delay_days') === 0
                            ? 'Con 0 Jaremar la ve al momento de registrarla, y desde ese momento ya no se puede corregir ni cancelar.'
                            : '1 día = 24 horas después de registrarla. Hasta ese momento se puede cancelar; corregir, solo el mismo día.'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $current = ReturnSetting::current();

        /** @var User $user */
        $user = Auth::user();

        $mode = ReturnPublicationMode::from(self::modeValue($data['publication_mode']));

        $updated = app(ReturnSettingsService::class)->update(
            windowBusinessDays: (int) $data['window_business_days'],
            mode: $mode,
            // Oculto cuando se publica al cerrar la ventana: se conserva el último elegido.
            delayDays: (int) ($data['publication_delay_days'] ?? $current->publication_delay_days),
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

    private static function isAfterRegistration(mixed $mode): bool
    {
        return self::modeValue($mode) === ReturnPublicationMode::AfterRegistration->value;
    }

    private static function modeValue(mixed $mode): string
    {
        return $mode instanceof ReturnPublicationMode ? $mode->value : (string) $mode;
    }
}
