<?php

namespace App\Filament\Resources\Edt\Routes;

use App\Filament\Resources\Edt\Routes\Pages\CreateEdtRoute;
use App\Filament\Resources\Edt\Routes\Pages\EditEdtRoute;
use App\Filament\Resources\Edt\Routes\Pages\ListEdtRoutes;
use App\Filament\Resources\Edt\Routes\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Edt\Routes\Schemas\EdtRouteForm;
use App\Filament\Resources\Edt\Routes\Tables\EdtRoutesTable;
use App\Models\Edt\EdtRoute;
use App\Support\Edt\EdtModule;
use App\Support\WarehouseScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rutas del EDT: municipios de una bodega, días de visita opcionales y el
 * historial de qué vendedor la tiene (AssignmentsRelationManager).
 *
 * Filtrado por bodega con WarehouseScope; EdtRoutePolicy repite el chequeo.
 */
class EdtRouteResource extends Resource
{
    protected static ?string $model = EdtRoute::class;

    protected static ?string $slug = 'edt/rutas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Ruta';

    protected static ?string $pluralModelLabel = 'Rutas';

    protected static ?int $navigationSort = 2;

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
        return EdtRouteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EdtRoutesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AssignmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdtRoutes::route('/'),
            'create' => CreateEdtRoute::route('/create'),
            'edit' => EditEdtRoute::route('/{record}/edit'),
        ];
    }
}
