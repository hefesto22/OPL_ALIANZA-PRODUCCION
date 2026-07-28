<?php

declare(strict_types=1);

namespace App\Filament\Resources\Manifests\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * "Dinero Aplicado": de qué boletas salió el dinero que cubre ESTE manifiesto.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ EXISTE, SI YA ESTÁ LA PESTAÑA "DEPÓSITOS"
 * ─────────────────────────────────────────────────────────────────────
 *  La pestaña Depósitos lista las boletas REGISTRADAS DESDE este manifiesto
 *  (deposits.manifest_id). Desde la aplicación multi-manifiesto esas dos cosas
 *  dejaron de ser lo mismo:
 *
 *    - Una boleta registrada desde el manifiesto A puede aportar HNL 0.32 a
 *      este manifiesto. En la pestaña Depósitos NO aparece — y sin embargo su
 *      dinero está acá, sumando en "Depositado".
 *    - Una boleta registrada desde acá puede tener la mayor parte aplicada
 *      en otros manifiestos.
 *
 *  Sin esta pestaña, un operador que ve "Depositado: HNL 160,620.78" pero solo
 *  una boleta de HNL 160,620.46 en la lista no tiene forma de explicar los
 *  0.32 restantes. Acá se ve exactamente de dónde vino cada lempira.
 *
 *  Es de solo lectura a propósito: una línea de reparto no se edita por
 *  separado — se edita la boleta (que recalcula todo su reparto) o se cancela.
 */
class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    protected static ?string $title = 'Dinero Aplicado';

    protected static ?string $label = 'Aplicación';

    protected static ?string $pluralLabel = 'Aplicaciones';

    /**
     * Mismo permiso que la pestaña Depósitos: es la misma información
     * financiera vista desde el otro lado.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('viewDeposits', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['deposit.manifest:id,number', 'deposit.createdBy:id,name'])
                // Un depósito cancelado conserva su reparto en BD para poder
                // restaurarlo, pero su dinero ya no cuenta — mostrarlo acá
                // haría que la suma de la tabla no cuadre con "Depositado".
                ->whereHas('deposit', fn (Builder $q) => $q->whereNull('cancelled_at')))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('deposit.deposit_date')
                    ->label('Fecha de Depósito')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Aplicado aquí')
                    ->money('HNL')
                    ->weight('bold')
                    ->color('success')
                    ->summarize(
                        \Filament\Tables\Columns\Summarizers\Sum::make()
                            ->label('Total aplicado')
                            ->money('HNL')
                    ),

                TextColumn::make('deposit.amount')
                    ->label('Total de la boleta')
                    ->money('HNL')
                    ->color('gray')
                    ->description(fn ($record): ?string => (float) $record->deposit->amount != (float) $record->amount
                        ? 'Repartida entre varios manifiestos'
                        : null),

                TextColumn::make('deposit.manifest.number')
                    ->label('Registrada desde')
                    ->badge()
                    ->color(fn ($record): string => (int) $record->deposit->manifest_id === (int) $record->manifest_id
                        ? 'gray'
                        : 'warning')
                    ->tooltip(fn ($record): ?string => (int) $record->deposit->manifest_id === (int) $record->manifest_id
                        ? null
                        : 'Este dinero llegó como excedente de otro manifiesto'),

                TextColumn::make('deposit.bank')
                    ->label('Banco')
                    ->placeholder('—'),

                TextColumn::make('deposit.reference')
                    ->label('Referencia')
                    ->placeholder('—')
                    ->copyable(),

                TextColumn::make('deposit.createdBy.name')
                    ->label('Registrado por')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->emptyStateHeading('Sin dinero aplicado')
            ->emptyStateDescription('Este manifiesto todavía no recibió depósitos.');
    }
}
