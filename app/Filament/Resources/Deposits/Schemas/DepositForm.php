<?php

namespace App\Filament\Resources\Deposits\Schemas;

use App\Models\Deposit;
use App\Models\Manifest;
use App\Support\WarehouseScope;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DepositForm
{
    public static function make(Schema $schema): Schema
    {
        return $schema->components([

            // ── Selección de manifiesto ────────────────────────────────
            Section::make('Seleccionar Manifiesto')
                ->description('Solo se muestran manifiestos con saldo pendiente de depósito.')
                ->schema([
                    Select::make('manifest_id')
                        ->label('Manifiesto')
                        ->required()
                        ->options(function (?Deposit $record) {
                            // Manifiestos con saldo pendiente, filtrados por bodega del usuario
                            $query = Manifest::query()
                                ->whereIn('status', ['imported', 'processing'])
                                ->whereRaw('total_to_deposit > total_deposited')
                                ->orderBy('date', 'desc');

                            // Usuarios de bodega solo ven sus propios manifiestos
                            WarehouseScope::apply($query);

                            $manifests = $query->get();

                            // En edición: asegurar que el manifiesto actual siempre aparezca
                            if ($record && $record->manifest_id) {
                                $current = Manifest::find($record->manifest_id);
                                if ($current && !$manifests->contains('id', $current->id)) {
                                    $manifests->prepend($current);
                                }
                            }

                            return $manifests->mapWithKeys(fn($m) => [
                                $m->id => sprintf(
                                    '#%s  |  %s  |  Pendiente: HNL %s',
                                    $m->number,
                                    Carbon::parse($m->date)->format('d/m/Y'),
                                    number_format(
                                        max(0, (float)$m->total_to_deposit - (float)$m->total_deposited),
                                        2
                                    )
                                )
                            ]);
                        })
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            if (!$state) {
                                $set('_manifest_total',     null);
                                $set('_manifest_deposited', null);
                                $set('_manifest_pending',   null);
                                return;
                            }

                            $manifest = Manifest::find($state);
                            if (!$manifest) return;

                            $pending = max(0, (float)$manifest->total_to_deposit - (float)$manifest->total_deposited);

                            $set('_manifest_total',     'HNL ' . number_format($manifest->total_to_deposit, 2));
                            $set('_manifest_deposited', 'HNL ' . number_format($manifest->total_deposited, 2));
                            $set('_manifest_pending',   'HNL ' . number_format($pending, 2));
                        })
                        ->columnSpanFull(),

                    // ── Resumen informativo del manifiesto ────────────
                    Grid::make(3)
                        ->schema([
                            TextInput::make('_manifest_total')
                                ->label('Total a Depositar')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('—'),

                            TextInput::make('_manifest_deposited')
                                ->label('Ya Depositado')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('—'),

                            TextInput::make('_manifest_pending')
                                ->label('Saldo Pendiente')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('—'),
                        ])
                        ->hidden(fn(Get $get) => !$get('manifest_id')),
                ]),

            // ── Datos del depósito ─────────────────────────────────────
            Section::make('Datos del Depósito')
                ->hidden(fn(Get $get) => !$get('manifest_id'))
                ->schema([
                    Grid::make(2)->schema([

                        TextInput::make('amount')
                            ->label('Monto a Depositar')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('HNL')
                            ->placeholder('0.00')
                            ->helperText('Puedes ajustar el monto para depósitos parciales.'),

                        DatePicker::make('deposit_date')
                            ->label('Fecha de Depósito')
                            ->required()
                            ->default(today())
                            ->maxDate(today()),

                        TextInput::make('bank')
                            ->label('Banco')
                            ->maxLength(100)
                            ->placeholder('Ej. Banco Atlántida'),

                        TextInput::make('reference')
                            ->label('Referencia / No. Boleta')
                            ->maxLength(100)
                            ->placeholder('Número de referencia'),

                        Textarea::make('observations')
                            ->label('Observaciones')
                            ->rows(3)
                            ->columnSpan(2)
                            ->placeholder('Notas adicionales...'),
                    ]),
                ]),
        ]);
    }
}