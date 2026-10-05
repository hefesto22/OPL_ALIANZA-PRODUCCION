<?php

namespace App\Filament\Resources\Edt\Sellers\Schemas;

use App\Casts\Uppercase;
use App\Models\Edt\EdtSeller;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\WarehouseScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Formulario del vendedor y de su acceso al sistema.
 *
 * email y password no son columnas de edt_sellers: los recibe
 * EdtSellerService para crear o actualizar el usuario del vendedor.
 */
class EdtSellerForm
{
    /** Solo visual: el cast Uppercase del modelo es el que normaliza. */
    private const UPPERCASE_INPUT = ['style' => 'text-transform: uppercase'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vendedor')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(20)
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => Uppercase::normalize($state))
                        ->unique(ignoreRecord: true)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->placeholder('Ej. V-01'),

                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150)
                        ->extraInputAttributes(self::UPPERCASE_INPUT),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(20)
                        ->placeholder('Ej. 9999-9999'),

                    Select::make('warehouse_id')
                        ->label('Bodega')
                        ->relationship(
                            'warehouse',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => WarehouseScope::apply($query, 'id')->orderBy('code'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Warehouse $record): string => "{$record->code} · {$record->name}")
                        ->preload()
                        ->required()
                        ->default(fn (): ?int => self::defaultWarehouseId())
                        // Con historial de rutas no cambia de bodega (sus rutas eran de esa bodega).
                        ->disabled(fn (?EdtSeller $record): bool => $record?->assignments()->exists() ?? false)
                        ->helperText(fn (?EdtSeller $record): string => ($record?->assignments()->exists() ?? false)
                            ? 'Ya tuvo rutas en esta bodega: no se puede cambiar. Para otra bodega, crea un vendedor nuevo.'
                            : 'Todo lo que venda se factura desde esta bodega.'),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Inactivo: no puede entrar al sistema.'),
                ]),

            Section::make('Acceso al sistema')
                ->description('Con este correo y contraseña el vendedor entra desde su celular y ve solo los clientes de sus rutas.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->required()
                        ->maxLength(150)
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => $state === null ? null : mb_strtolower(trim($state)))
                        ->unique(
                            table: User::class,
                            column: 'email',
                            ignorable: fn (?EdtSeller $record): ?User => $record?->user,
                        ),

                    TextInput::make('password')
                        ->label(fn (string $operation): string => $operation === 'create' ? 'Contraseña' : 'Nueva contraseña')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->maxLength(100)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Déjala vacía para no cambiarla.' : null),
                ]),
        ]);
    }

    private static function defaultWarehouseId(): ?int
    {
        $ids = WarehouseScope::apply(Warehouse::query(), 'id')->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
