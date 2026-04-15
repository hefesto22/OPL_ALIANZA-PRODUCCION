<?php

namespace App\Filament\Resources\Invoices;

use App\Models\Invoice;
use App\Support\WarehouseScope;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Resource mínimo para Invoice.
 *
 * Existe para que FilamentShield descubra el modelo y genere
 * sus permisos automáticamente (ViewAny:Invoice, View:Invoice, etc.).
 *
 * Oculto del menú lateral — las facturas se consultan dentro de
 * ManifestResource vía InvoicesRelationManager.
 * No tiene form/table propio porque las facturas se crean solo vía API.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $modelLabel = 'Factura';

    protected static ?string $pluralModelLabel = 'Facturas';

    /** Oculto del menú — las facturas se ven dentro de Manifiestos. */
    protected static bool $shouldRegisterNavigation = false;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);

        return WarehouseScope::apply($query);
    }

    public static function getPages(): array
    {
        return [];
    }
}
