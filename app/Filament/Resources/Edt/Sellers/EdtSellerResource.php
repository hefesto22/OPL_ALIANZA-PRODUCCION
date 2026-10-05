<?php

namespace App\Filament\Resources\Edt\Sellers;

use App\Filament\Resources\Edt\Sellers\Pages\CreateEdtSeller;
use App\Filament\Resources\Edt\Sellers\Pages\EditEdtSeller;
use App\Filament\Resources\Edt\Sellers\Pages\ListEdtSellers;
use App\Filament\Resources\Edt\Sellers\Schemas\EdtSellerForm;
use App\Filament\Resources\Edt\Sellers\Tables\EdtSellersTable;
use App\Models\Edt\EdtSeller;
use App\Support\Edt\EdtModule;
use App\Support\WarehouseScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vendedores de ruta del EDT. Cada uno es de una bodega: el listado y la
 * apertura por URL pasan por WarehouseScope; EdtSellerPolicy repite el
 * chequeo por registro.
 *
 * El alta y los cambios crean/actualizan también el usuario del vendedor
 * (EdtSellerService), por eso las páginas no usan el guardado estándar.
 */
class EdtSellerResource extends Resource
{
    protected static ?string $model = EdtSeller::class;

    protected static ?string $slug = 'edt/vendedores';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Vendedor';

    protected static ?string $pluralModelLabel = 'Vendedores';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return EdtModule::NAVIGATION_GROUP;
    }

    public static function getEloquentQuery(): Builder
    {
        return WarehouseScope::apply(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return EdtSellerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EdtSellersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdtSellers::route('/'),
            'create' => CreateEdtSeller::route('/create'),
            'edit' => EditEdtSeller::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }
}
