<?php

namespace App\Filament\Resources\Edt\Suppliers;

use App\Filament\Resources\Edt\Suppliers\Pages\CreateEdtSupplier;
use App\Filament\Resources\Edt\Suppliers\Pages\EditEdtSupplier;
use App\Filament\Resources\Edt\Suppliers\Pages\ListEdtSuppliers;
use App\Filament\Resources\Edt\Suppliers\Schemas\EdtSupplierForm;
use App\Filament\Resources\Edt\Suppliers\Tables\EdtSuppliersTable;
use App\Models\Edt\EdtSupplier;
use App\Support\Edt\EdtModule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Proveedores del módulo EDT (grupo "EDT Sistema").
 *
 * Catálogo global de la empresa: no pertenece a ninguna bodega, por eso no
 * aplica WarehouseScope y está en GLOBAL_RESOURCES del
 * MultiTenantContractTest. La autorización es solo la Policy
 * (EdtSupplierPolicy) con permisos Shield *:EdtSupplier.
 */
class EdtSupplierResource extends Resource
{
    protected static ?string $model = EdtSupplier::class;

    protected static ?string $slug = 'edt/proveedores';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return EdtModule::NAVIGATION_GROUP;
    }

    public static function form(Schema $schema): Schema
    {
        return EdtSupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EdtSuppliersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdtSuppliers::route('/'),
            'create' => CreateEdtSupplier::route('/create'),
            'edit' => EditEdtSupplier::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }
}
