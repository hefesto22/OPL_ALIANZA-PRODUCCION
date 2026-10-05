<?php

namespace App\Filament\Resources\Edt\Clients\Schemas;

use App\Casts\Uppercase;
use App\Models\Edt\EdtClient;
use App\Models\Geo\Department;
use App\Models\Geo\Municipality;
use App\Models\Warehouse;
use App\Services\Edt\EdtClientCodeGenerator;
use App\Support\WarehouseScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Exists;

/**
 * Formulario de clientes del EDT.
 *
 * - Bodega: un usuario de bodega solo puede elegir las suyas (opciones y
 *   validación). Si tiene una sola, ya viene elegida.
 * - Zona: departamento → municipio (la lista se filtra). La validación y
 *   la FK compuesta de la tabla impiden un municipio de otro departamento.
 * - Código: vacío al crear = automático (C-000001…).
 */
class EdtClientForm
{
    /** Solo visual: el cast Uppercase del modelo es el que normaliza. */
    private const UPPERCASE_INPUT = ['style' => 'text-transform: uppercase'];

    /** Sugerencias de tipo de negocio; se suman los que ya existan. */
    private const BUSINESS_TYPES = [
        'PULPERÍA', 'MINISÚPER', 'SUPERMERCADO', 'ABARROTERÍA', 'DEPÓSITO',
        'FARMACIA', 'RESTAURANTE', 'CAFETERÍA', 'GASOLINERA', 'MAYORISTA',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cliente')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('code')
                        ->label('Código')
                        ->maxLength(20)
                        // Al editar ya tiene código y no puede quedar vacío.
                        ->required(fn (string $operation): bool => $operation === 'edit')
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => Uppercase::normalize($state))
                        ->unique(ignoreRecord: true)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->placeholder(fn (string $operation): ?string => $operation === 'create' ? 'AUTOMÁTICO' : null)
                        ->helperText(fn (string $operation): ?string => $operation === 'create'
                            ? 'Déjalo vacío y el sistema asigna el siguiente ('.EdtClientCodeGenerator::format(1).', '.EdtClientCodeGenerator::format(2).'…), o escribe el que ya usan.'
                            : null),

                    TextInput::make('name')
                        ->label('Nombre o razón social')
                        ->required()
                        ->maxLength(150)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->helperText('Así sale en la factura.'),

                    TextInput::make('business_name')
                        ->label('Nombre del negocio')
                        ->maxLength(150)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->placeholder('Ej. PULPERÍA LA BENDICIÓN'),

                    TextInput::make('business_type')
                        ->label('Tipo de negocio')
                        ->maxLength(60)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->datalist(fn (): array => collect(self::BUSINESS_TYPES)
                            ->merge(EdtClient::query()->whereNotNull('business_type')->distinct()->pluck('business_type'))
                            ->unique()
                            ->sort()
                            ->values()
                            ->all()),

                    TextInput::make('rtn')
                        ->label('RTN')
                        ->maxLength(20)
                        // 14 dígitos; se aceptan guiones o espacios al escribirlo
                        // (el modelo los quita antes de guardar).
                        ->regex('/^\D*(\d\D*){14}$/')
                        ->validationMessages([
                            'regex' => 'El RTN debe tener 14 dígitos.',
                        ])
                        ->placeholder('Ej. 0401-1990-123456'),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(20)
                        ->placeholder('Ej. 9999-9999'),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false),
                ]),

            Section::make('Bodega y zona')
                ->description('La bodega es la que le factura. La zona (departamento y municipio) es la que usarán los vendedores de esa bodega para encontrarlo.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
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
                        // Un usuario de bodega no puede guardar en una bodega ajena
                        // aunque mande el id a mano.
                        ->in(fn (): array => self::allowedWarehouseIds())
                        ->default(fn (): ?int => self::defaultWarehouseId())
                        ->live()
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            // Ayuda: si la zona está vacía, propone el departamento de la bodega.
                            if (blank($get('department_id')) && ($departmentId = self::departmentForWarehouse($state)) !== null) {
                                $set('department_id', $departmentId);
                            }
                        }),

                    Select::make('department_id')
                        ->label('Departamento')
                        ->options(fn (): array => Department::options())
                        ->searchable()
                        ->required()
                        ->default(fn (): ?int => self::departmentForWarehouse(self::defaultWarehouseId()))
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('municipality_id', null)),

                    Select::make('municipality_id')
                        ->label('Municipio')
                        ->options(fn (Get $get): array => Municipality::optionsFor($get('department_id')))
                        ->searchable()
                        ->required()
                        ->exists(
                            'hn_municipalities',
                            'id',
                            fn (Exists $rule, Get $get): Exists => $rule->where('department_id', (int) $get('department_id')),
                        )
                        ->helperText(fn (Get $get): ?string => blank($get('department_id')) ? 'Elige primero el departamento.' : null),

                    TextInput::make('neighborhood')
                        ->label('Barrio, colonia o aldea')
                        ->maxLength(120)
                        ->extraInputAttributes(self::UPPERCASE_INPUT),

                    TextInput::make('address')
                        ->label('Dirección o referencia')
                        ->maxLength(255)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->placeholder('Ej. 2 CUADRAS AL SUR DE LA IGLESIA CATÓLICA')
                        ->columnSpanFull(),
                ]),

            Section::make('Crédito')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('credit_enabled')
                        ->label('Cliente con crédito')
                        ->default(false)
                        ->live()
                        ->inline(false)
                        ->helperText('El límite y los días son opcionales: se pueden definir después.'),

                    TextInput::make('credit_limit')
                        ->label('Límite de crédito')
                        ->numeric()
                        ->prefix('L')
                        ->minValue(0.01)
                        ->maxValue(9999999999.99)
                        ->rule('decimal:0,2')
                        ->placeholder('Sin definir')
                        ->visible(fn (Get $get): bool => (bool) $get('credit_enabled')),

                    TextInput::make('credit_days')
                        ->label('Días de crédito')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix('días')
                        ->placeholder('Sin definir')
                        ->visible(fn (Get $get): bool => (bool) $get('credit_enabled')),
                ]),
        ]);
    }

    /**
     * Bodegas en las que el usuario puede tener clientes: las suyas si es
     * usuario de bodega, todas si es global.
     *
     * @return list<int>
     */
    private static function allowedWarehouseIds(): array
    {
        return WarehouseScope::apply(Warehouse::query(), 'id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Con una sola bodega permitida, ya viene elegida.
     */
    private static function defaultWarehouseId(): ?int
    {
        $ids = self::allowedWarehouseIds();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * El departamento del catálogo que corresponde a la bodega (por el
     * nombre guardado en warehouses.department, ej. "Copán" → COPÁN).
     * Null si no hay bodega o el nombre no coincide.
     */
    private static function departmentForWarehouse(int|string|null $warehouseId): ?int
    {
        if (blank($warehouseId)) {
            return null;
        }

        $name = Uppercase::normalize(Warehouse::query()->whereKey($warehouseId)->value('department'));

        if ($name === null) {
            return null;
        }

        $id = Department::query()->where('name', $name)->value('id');

        return $id === null ? null : (int) $id;
    }
}
