<?php

namespace App\Filament\Resources\Manifests;

use App\Filament\Resources\Manifests\Pages\CreateManifest;
use App\Filament\Resources\Manifests\Pages\EditManifest;
use App\Filament\Resources\Manifests\Pages\ListManifests;
use App\Filament\Resources\Manifests\Pages\ViewManifest;
use App\Filament\Resources\Manifests\Schemas\ManifestForm;
use App\Filament\Resources\Manifests\Schemas\ManifestInfolist;
use App\Filament\Resources\Manifests\Tables\ManifestsTable;
use App\Models\Manifest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class ManifestResource extends Resource
{
    protected static ?string $model = Manifest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Manifiestos';

    protected static ?string $modelLabel = 'Manifiesto';

    protected static ?string $pluralModelLabel = 'Manifiestos';

    protected static ?int $navigationSort = 1;

    /**
     * Muestra en el menú lateral cuántos manifiestos están importados
     * (pendientes de gestión/cierre), para que los usuarios sepan de un
     * vistazo si tienen trabajo pendiente sin entrar al listado.
     */
    public static function getNavigationBadge(): ?string
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = static::getEloquentQuery()->where('status', 'imported');

        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ManifestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ManifestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ManifestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\Manifests\RelationManagers\InvoicesRelationManager::class,
            \App\Filament\Resources\Manifests\RelationManagers\DepositsRelationManager::class, // ← agregar
            \App\Filament\Resources\Manifests\RelationManagers\ReturnsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListManifests::route('/'),
            'create' => CreateManifest::route('/create'),
            'view'   => ViewManifest::route('/{record}'),
            'edit'   => EditManifest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['supplier', 'warehouse', 'warehouseTotals']);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        // Usuario de bodega: solo ve manifiestos que tengan facturas de su bodega
        if ($user && $user->isWarehouseUser()) {
            $query->whereHas('invoices', function (Builder $q) use ($user) {
                $q->where('warehouse_id', $user->warehouse_id)
                    ->where('status', 'imported');
            });
        }

        return $query;
    }
}
