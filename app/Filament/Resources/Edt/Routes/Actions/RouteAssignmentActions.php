<?php

namespace App\Filament\Resources\Edt\Routes\Actions;

use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtSeller;
use App\Services\Edt\EdtRouteAssignmentService;
use App\Services\Edt\EdtRuleException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Acciones sobre el vendedor de una ruta. Todas exigen el permiso
 * AssignSeller:EdtRoute sobre esa ruta (EdtRoutePolicy::assignSeller) y
 * delegan en EdtRouteAssignmentService, que bloquea la ruta y valida que
 * las fechas no se crucen. Un error de regla se muestra como aviso y el
 * modal queda abierto para corregir.
 */
final class RouteAssignmentActions
{
    public static function assign(): Action
    {
        return Action::make('assignSeller')
            ->label('Asignar vendedor')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->authorize('assignSeller')
            ->modalHeading(fn (EdtRoute $record): string => "Asignar vendedor · {$record->name}")
            ->modalDescription('Si la ruta ya tiene vendedor, el actual la deja el día anterior a la fecha de inicio. Queda en el historial.')
            ->modalSubmitActionLabel('Asignar')
            ->schema([
                Select::make('seller_id')
                    ->label('Vendedor')
                    ->options(fn (EdtRoute $record): array => EdtSeller::query()
                        ->where('warehouse_id', $record->warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get(['id', 'code', 'name'])
                        ->mapWithKeys(fn (EdtSeller $seller): array => [$seller->id => "{$seller->name} ({$seller->code})"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Solo vendedores activos de la bodega de la ruta.'),

                DatePicker::make('starts_on')
                    ->label('Desde')
                    ->required()
                    ->default(fn (): string => today()->toDateString()),
            ])
            ->action(function (EdtRoute $record, array $data, Action $action): void {
                $seller = EdtSeller::query()->findOrFail($data['seller_id']);

                self::run($action, fn () => app(EdtRouteAssignmentService::class)
                    ->assign($record, $seller, Carbon::parse($data['starts_on'])));

                Notification::make()
                    ->title('Vendedor asignado')
                    ->body("{$seller->name} tiene la ruta desde el ".Carbon::parse($data['starts_on'])->format('d/m/Y').'.')
                    ->success()
                    ->send();
            });
    }

    public static function release(): Action
    {
        return Action::make('releaseSeller')
            ->label('Quitar vendedor')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('warning')
            ->authorize('assignSeller')
            // Solo si tiene un vendedor que ya empezó (uno programado se cancela).
            ->visible(fn (EdtRoute $record): bool => $record->assignments()
                ->whereNull('ends_on')
                ->where('starts_on', '<=', today()->toDateString())
                ->exists())
            ->modalHeading(fn (EdtRoute $record): string => "Quitar vendedor · {$record->name}")
            ->modalDescription('La ruta queda sin vendedor desde el día siguiente a la fecha que elijas.')
            ->modalSubmitActionLabel('Quitar')
            ->schema([
                DatePicker::make('ends_on')
                    ->label('Último día con la ruta')
                    ->required()
                    ->default(fn (): string => today()->toDateString()),
            ])
            ->action(function (EdtRoute $record, array $data, Action $action): void {
                self::run($action, fn () => app(EdtRouteAssignmentService::class)
                    ->release($record, Carbon::parse($data['ends_on'])));

                Notification::make()->title('Vendedor quitado de la ruta')->success()->send();
            });
    }

    public static function cancelScheduled(): Action
    {
        return Action::make('cancelScheduled')
            ->label('Cancelar programación')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('assignSeller')
            ->visible(fn (EdtRoute $record): bool => $record->assignments()
                ->whereNull('ends_on')
                ->where('starts_on', '>', today()->toDateString())
                ->exists())
            ->requiresConfirmation()
            ->modalHeading('Cancelar la asignación programada')
            ->modalDescription('Se borra la asignación que todavía no empieza y, si al programarla se le quitó la ruta al vendedor anterior, la vuelve a tener.')
            ->action(function (EdtRoute $record, Action $action): void {
                self::run($action, fn () => app(EdtRouteAssignmentService::class)->cancelScheduled($record));

                Notification::make()->title('Programación cancelada')->success()->send();
            });
    }

    /**
     * Corre la operación; si una regla la impide, avisa y detiene la acción.
     */
    private static function run(Action $action, callable $operation): void
    {
        try {
            $operation();
        } catch (EdtRuleException $e) {
            Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->send();

            $action->halt();
        }
    }
}
