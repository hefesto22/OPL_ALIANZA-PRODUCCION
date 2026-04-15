<?php

namespace App\Filament\Resources\Returns;

use App\Filament\Resources\Returns\Pages\CreateReturn;
use App\Filament\Resources\Returns\Pages\EditReturn;
use App\Filament\Resources\Returns\Pages\ListReturns;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Models\InvoiceReturn;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ReturnResource extends Resource
{
    protected static ?string $model = InvoiceReturn::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';
    protected static ?string $navigationLabel  = 'Devoluciones';
    protected static ?string $modelLabel       = 'Devolución';
    protected static ?string $pluralModelLabel = 'Devoluciones';



    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['invoice', 'returnReason', 'warehouse', 'manifest', 'createdBy:id,name']);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        if ($user->isWarehouseUser()) {
            $query->where('warehouse_id', $user->warehouse_id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListReturns::route('/'),
            'create' => CreateReturn::route('/create'),
            'view'   => ViewReturn::route('/{record}'),
            'edit'   => EditReturn::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }
}
