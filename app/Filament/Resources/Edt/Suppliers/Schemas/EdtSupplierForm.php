<?php

namespace App\Filament\Resources\Edt\Suppliers\Schemas;

use App\Casts\Uppercase;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EdtSupplierForm
{
    /**
     * Muestra en mayúsculas mientras se escribe. Lo que se guarda lo
     * normaliza el cast Uppercase del modelo; esto es solo visual.
     *
     * @var array<string, string>
     */
    private const UPPERCASE_INPUT = ['style' => 'text-transform: uppercase'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del proveedor')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(20)
                        // El modelo guarda el código en mayúsculas; la regla
                        // unique tiene que comparar ya normalizado, si no
                        // "cbc" pasaría la validación y chocaría en la BD.
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => Uppercase::normalize($state))
                        ->unique(ignoreRecord: true)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->placeholder('Ej. CBC'),

                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150)
                        ->extraInputAttributes(self::UPPERCASE_INPUT),

                    TextInput::make('rtn')
                        ->label('RTN')
                        ->maxLength(20)
                        // 14 dígitos; se aceptan guiones o espacios al escribirlo
                        // (el modelo los quita antes de guardar).
                        ->regex('/^\D*(\d\D*){14}$/')
                        ->validationMessages([
                            'regex' => 'El RTN debe tener 14 dígitos.',
                        ])
                        ->placeholder('Ej. 0501-1990-123456'),

                    TextInput::make('contact_name')
                        ->label('Persona de contacto')
                        ->maxLength(150)
                        ->extraInputAttributes(self::UPPERCASE_INPUT),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(20),

                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(150),

                    TextInput::make('address')
                        ->label('Dirección')
                        ->maxLength(255)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->columnSpanFull(),
                ]),

            Section::make('Condiciones comerciales')
                ->columns(2)
                ->schema([
                    TextInput::make('operation_discount_pct')
                        ->label('Descuento de operación')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99.99)
                        ->step(0.01)
                        ->default(15)
                        ->suffix('%')
                        ->helperText(
                            'Descuento que da el proveedor sobre su precio de lista: es la ganancia del EDT '.
                            '(costo = lista × (1 − descuento)). No es el ISV; el ISV se configura en cada producto. '.
                            'Si lo cambias, el costo de todos sus productos se recalcula y queda en su historial de precios.'
                        ),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }
}
