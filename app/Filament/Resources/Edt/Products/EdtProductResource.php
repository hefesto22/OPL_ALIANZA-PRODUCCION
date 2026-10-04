<?php

namespace App\Filament\Resources\Edt\Products;

use App\Filament\Resources\Edt\Products\Pages\CreateEdtProduct;
use App\Filament\Resources\Edt\Products\Pages\EditEdtProduct;
use App\Filament\Resources\Edt\Products\Pages\ListEdtProducts;
use App\Filament\Resources\Edt\Products\RelationManagers\PriceHistoryRelationManager;
use App\Filament\Resources\Edt\Products\Schemas\EdtProductForm;
use App\Filament\Resources\Edt\Products\Tables\EdtProductsTable;
use App\Models\Edt\EdtProduct;
use App\Support\Edt\EdtModule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Catálogo de productos del EDT (grupo "EDT Sistema").
 *
 * Global (sin bodega): está en GLOBAL_RESOURCES del MultiTenantContractTest.
 * Autorización por EdtProductPolicy; cambiar precios tiene permiso propio
 * (ChangePrice:EdtProduct).
 */
class EdtProductResource extends Resource
{
    protected static ?string $model = EdtProduct::class;

    protected static ?string $slug = 'edt/productos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return EdtModule::NAVIGATION_GROUP;
    }

    public static function form(Schema $schema): Schema
    {
        return EdtProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EdtProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PriceHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdtProducts::route('/'),
            'create' => CreateEdtProduct::route('/create'),
            'edit' => EditEdtProduct::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'description'];
    }
}
