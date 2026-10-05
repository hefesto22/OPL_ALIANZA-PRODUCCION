<?php

namespace App\Filament\Resources\Edt\Routes\RelationManagers;

use App\Models\Edt\EdtRouteAssignment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Historial de vendedores de la ruta: solo lectura. Se cambia con las
 * acciones del encabezado de la página (Asignar / Quitar / Cancelar
 * programación), que pasan por EdtRouteAssignmentService.
 */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Historial de vendedores';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view', $ownerRecord) ?? false;
    }

    /**
     * Las acciones del encabezado avisan con este evento para que la tabla
     * se actualice sin recargar la página.
     */
    #[On('refresh-route-assignments')]
    public function refreshAssignments(): void
    {
        // Basta con que Livewire vuelva a renderizar el componente.
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('seller.name')
                    ->label('Vendedor')
                    ->description(fn (EdtRouteAssignment $record): ?string => $record->seller?->code),

                TextColumn::make('starts_on')
                    ->label('Desde')
                    ->date('d/m/Y'),

                TextColumn::make('ends_on')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('Sigue'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->state(fn (EdtRouteAssignment $record): string => $record->statusOn())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EdtRouteAssignment::STATUS_CURRENT => 'success',
                        EdtRouteAssignment::STATUS_SCHEDULED => 'info',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('starts_on', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('seller:id,code,name'))
            ->paginated([10, 25, 50]);
    }
}
