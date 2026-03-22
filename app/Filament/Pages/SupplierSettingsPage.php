<?php

namespace App\Filament\Pages;

use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
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
use BackedEnum;

class SupplierSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected string $view = 'filament.pages.supplier-settings';

    protected static ?string $navigationLabel = 'Proveedor (Jaremar)';

    protected static ?string $title = 'Configuración del Proveedor';

    protected static ?int $navigationSort = 3;

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

    // Campos del formulario como propiedades públicas para Livewire
    public ?string $name         = null;
    public ?string $rtn          = null;
    public ?string $email        = null;
    public ?string $phone        = null;
    public ?string $phone2       = null;
    public ?string $address      = null;
    public ?string $neighborhood = null;
    public ?string $api_url      = null;
    public bool    $is_active    = true;

    public function mount(): void
    {
        $supplier = Supplier::first();

        if (!$supplier) {
            return;
        }

        $this->fill([
            'name'         => $supplier->name,
            'rtn'          => $supplier->rtn,
            'email'        => $supplier->email,
            'phone'        => $supplier->phone,
            'phone2'       => $supplier->phone2 ?? null,
            'address'      => $supplier->address,
            'neighborhood' => $supplier->neighborhood ?? null,
            'api_url'      => $supplier->api_url,
            'is_active'    => (bool) $supplier->is_active,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Información General')
                ->icon('heroicon-o-building-office-2')
                ->description('Datos del proveedor que aparecen en las facturas impresas.')
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre del proveedor')
                        ->required()
                        ->maxLength(150),

                    TextInput::make('rtn')
                        ->label('RTN')
                        ->required()
                        ->maxLength(20),

                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->maxLength(100),

                    TextInput::make('phone')
                        ->label('Teléfono principal')
                        ->maxLength(30),

                    TextInput::make('phone2')
                        ->label('Teléfono secundario')
                        ->maxLength(30),

                    TextInput::make('neighborhood')
                        ->label('Barrio / Colonia')
                        ->maxLength(100)
                        ->placeholder('Ej. LA GUADALUPE'),

                    TextInput::make('address')
                        ->label('Dirección')
                        ->maxLength(250)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Integración API')
                ->icon('heroicon-o-arrow-path')
                ->description('Configuración de la conexión con el sistema OPL de Jaremar.')
                ->schema([
                    TextInput::make('api_url')
                        ->label('URL del API de Jaremar')
                        ->maxLength(250)
                        ->placeholder('https://api.jaremar.com/v1')
                        ->helperText('URL base del sistema OPL de Jaremar, si aplica en el futuro.'),

                    Toggle::make('is_active')
                        ->label('Proveedor activo')
                        ->helperText('Si se desactiva, el sistema rechazará todas las llamadas entrantes de Jaremar.'),
                ]),
        ]);
    }

    public function save(): void
    {
        $supplier = Supplier::first();

        if (!$supplier) {
            Notification::make()
                ->title('No se encontró el proveedor en el sistema.')
                ->danger()
                ->send();
            return;
        }

        $data = $this->form->getState();

        $supplier->update([
            'name'         => $data['name'],
            'rtn'          => $data['rtn'],
            'email'        => $data['email'],
            'phone'        => $data['phone'],
            'phone2'       => $data['phone2'] ?? null,
            'address'      => $data['address'],
            'neighborhood' => $data['neighborhood'] ?? null,
            'api_url'      => $data['api_url'],
            'is_active'    => $data['is_active'],
        ]);

        Notification::make()
            ->title('Configuración del proveedor actualizada correctamente.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar cambios')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action('save'),
        ];
    }
}