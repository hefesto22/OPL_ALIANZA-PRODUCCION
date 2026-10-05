<?php

namespace App\Filament\Resources\Edt\Clients;

use App\Filament\Resources\Edt\Clients\Pages\CreateEdtClient;
use App\Filament\Resources\Edt\Clients\Pages\EditEdtClient;
use App\Filament\Resources\Edt\Clients\Pages\ListEdtClients;
use App\Filament\Resources\Edt\Clients\Schemas\EdtClientForm;
use App\Filament\Resources\Edt\Clients\Tables\EdtClientsTable;
use App\Models\Edt\EdtClient;
use App\Support\Edt\EdtModule;
use App\Support\WarehouseScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clientes del módulo EDT (grupo "EDT Sistema").
 *
 * Cada cliente es de una bodega: el listado, la búsqueda global y la
 * apertura por URL pasan por getEloquentQuery(), que aplica WarehouseScope
 * (un usuario de OAC no ve clientes de OAS). EdtClientPolicy repite el
 * chequeo por registro como segunda línea de defensa.
 */
class EdtClientResource extends Resource
{
    protected static ?string $model = EdtClient::class;

    protected static ?string $slug = 'edt/clientes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    // Primero del grupo: es con lo que más se trabaja en el día a día.
    protected static ?int $navigationSort = 0;

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
        return EdtClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EdtClientsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdtClients::route('/'),
            'create' => CreateEdtClient::route('/create'),
            'edit' => EditEdtClient::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name', 'business_name'];
    }
}
