<?php

namespace App\Filament\Resources\Edt\Orders;

use App\Filament\Resources\Edt\Orders\Pages\ListEdtOrders;
use App\Filament\Resources\Edt\Orders\Pages\PickEdtOrderClient;
use App\Filament\Resources\Edt\Orders\Pages\TakeEdtOrder;
use App\Filament\Resources\Edt\Orders\Pages\ViewEdtOrder;
use App\Filament\Resources\Edt\Orders\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Edt\Orders\Schemas\EdtOrderInfolist;
use App\Filament\Resources\Edt\Orders\Tables\EdtOrdersTable;
use App\Models\Edt\EdtOrder;
use App\Services\Edt\EdtOrderService;
use App\Support\Edt\EdtModule;
use App\Support\WarehouseScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Preventas del EDT (grupo "EDT Sistema").
 *
 * - El vendedor las toma desde el celular: «Nueva preventa» → elige
 *   cliente (PickEdtOrderClient) → arma el pedido (TakeEdtOrder) → envía.
 * - La bodega las revisa en ViewEdtOrder: baja lo que no hay, aprueba o
 *   rechaza.
 *
 * getEloquentQuery() limita por bodega (WarehouseScope) y por usuario
 * (EdtOrderService::visibleTo: el vendedor solo las suyas, nadie más ve
 * borradores). EdtOrderPolicy repite el chequeo por registro.
 */
class EdtOrderResource extends Resource
{
    protected static ?string $model = EdtOrder::class;

    protected static ?string $slug = 'edt/preventas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Preventa';

    protected static ?string $pluralModelLabel = 'Preventas';

    // Antes que Clientes: es lo que se usa todos los días.
    protected static ?int $navigationSort = -1;

    public static function getNavigationGroup(): ?string
    {
        return EdtModule::NAVIGATION_GROUP;
    }

    public static function getEloquentQuery(): Builder
    {
        return EdtOrderService::visibleTo(
            WarehouseScope::apply(parent::getEloquentQuery()),
            Auth::user(),
        );
    }

    public static function infolist(Schema $schema): Schema
    {
        return EdtOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EdtOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdtOrders::route('/'),
            'pick' => PickEdtOrderClient::route('/nueva'),
            'take' => TakeEdtOrder::route('/{record}/tomar'),
            'view' => ViewEdtOrder::route('/{record}'),
        ];
    }
}
