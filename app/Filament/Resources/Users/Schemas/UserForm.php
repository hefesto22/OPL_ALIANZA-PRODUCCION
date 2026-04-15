<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Warehouse;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información Personal')
                    ->description('Nombre, correo electrónico y datos de contacto del usuario.')
                    ->icon('heroicon-o-user')
                    ->aside()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre completo')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-o-user')
                            ->placeholder('Ej: Juan Pérez'),
                        TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->prefixIcon('heroicon-o-envelope')
                            ->placeholder('correo@ejemplo.com'),
                        TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(20)
                            ->prefixIcon('heroicon-o-phone')
                            ->placeholder('+504 9999-9999'),
                    ]),

                Section::make('Foto de Perfil')
                    ->description('Imagen de avatar del usuario. Formato PNG o JPG, máximo 2MB.')
                    ->icon('heroicon-o-camera')
                    ->aside()
                    ->schema([
                        FileUpload::make('avatar_url')
                            ->label('')
                            ->image()
                            ->avatar()
                            ->directory('avatars')
                            ->maxSize(2048),
                    ]),

                Section::make('Seguridad')
                    ->description('Contraseña de acceso y estado de verificación del correo electrónico.')
                    ->icon('heroicon-o-lock-closed')
                    ->aside()
                    ->schema([
                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->rule(Password::default())
                            ->prefixIcon('heroicon-o-key')
                            ->placeholder(fn (string $operation): string =>
                                $operation === 'edit' ? 'Dejar vacío para mantener actual' : 'Mínimo 8 caracteres'
                            ),
                        DateTimePicker::make('email_verified_at')
                            ->label('Email verificado el')
                            ->prefixIcon('heroicon-o-check-badge')
                            ->placeholder('Sin verificar'),
                    ]),

                Section::make('Roles y Permisos')
                    ->description('Define qué puede hacer este usuario dentro del sistema.')
                    ->icon('heroicon-o-shield-check')
                    ->aside()
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('Seleccionar roles'),

                        Select::make('warehouse_id')
                            ->label('Bodega asignada')
                            ->options(Warehouse::pluck('code', 'id'))
                            ->searchable()
                            ->nullable()
                            ->rules(['nullable', 'exists:warehouses,id'])
                            ->placeholder('Sin bodega (Admin / Haremar)')
                            ->helperText('Solo asignar bodega a usuarios operadores. Dejar vacío para admins y Haremar.')
                            ->prefixIcon('heroicon-o-building-storefront'),
                    ]),

                Section::make('Estado de la Cuenta')
                    ->description('Controla si el usuario puede acceder al sistema.')
                    ->icon('heroicon-o-power')
                    ->aside()
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Usuario activo')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger')
                            ->helperText('Si se desactiva, el usuario no podrá iniciar sesión en el panel.'),
                    ]),
            ]);
    }
}