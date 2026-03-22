<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Spatie\Activitylog\Models\Activity;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Registro de Actividad';

    protected static ?string $pluralModelLabel = 'Registros de Actividad';

    protected static ?int $navigationSort = 99;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers para formatear properties de forma legible
    // ─────────────────────────────────────────────────────────────

    /**
     * Formatea un array de properties en líneas clave: valor legibles.
     */
    protected static function formatProperties(mixed $data): string
    {
        if (empty($data) || !is_array($data)) {
            return '—';
        }

        $labels = [
            'endpoint'       => 'Endpoint',
            'ip'             => 'IP',
            'fecha'          => 'Fecha consultada',
            'pagina'         => 'Página',
            'total'          => 'Total registros',
            'desde_cache'    => 'Desde caché',
            'resultado'      => 'Resultado',
            'batch_uuid'     => 'Batch UUID',
            'manifest_number'=> 'N° Manifiesto',
            'invoice_number' => 'N° Factura',
            'conflicts'      => 'Conflictos',
            'changed_fields' => 'Campos cambiados',
        ];

        $lines = [];
        foreach ($data as $key => $value) {
            $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));

            if (is_bool($value)) {
                $value = $value ? 'Sí' : 'No';
            } elseif (is_array($value)) {
                $value = implode(', ', array_map(
                    fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v,
                    $value
                ));
            } elseif (is_null($value)) {
                $value = '—';
            }

            $lines[] = "**{$label}:** {$value}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Formatea un diff (old vs attributes) en líneas comparativas.
     */
    protected static function formatDiff(mixed $data, string $side): string
    {
        if (empty($data) || !is_array($data)) {
            return '—';
        }

        $lines = [];
        foreach ($data as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', $key));

            if (is_bool($value)) {
                $value = $value ? 'Sí' : 'No';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } elseif (is_null($value)) {
                $value = '—';
            }

            $lines[] = "**{$label}:** {$value}";
        }

        return implode("\n\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────
    // Table
    // ─────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->label('Tipo')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('subject_type')
                    ->label('Modelo')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('causer.name')
                    ->label('Realizado por')
                    ->placeholder('Sistema')
                    ->searchable()
                    ->icon('heroicon-o-user'),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Tipo de log')
                    ->options(fn () => Activity::distinct()->pluck('log_name', 'log_name')->toArray()),
                SelectFilter::make('subject_type')
                    ->label('Modelo')
                    ->options(fn () => Activity::distinct()
                        ->whereNotNull('subject_type')
                        ->pluck('subject_type')
                        ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
                        ->toArray()),
                Filter::make('created_at')
                    ->indicateUsing(function (array $data): ?string {
                        if ($data['from'] ?? null) {
                            return 'Desde: ' . $data['from'];
                        }
                        return null;
                    })
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    // ─────────────────────────────────────────────────────────────
    // Infolist (View)
    // ─────────────────────────────────────────────────────────────

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([

                // ── Cabecera siempre visible ──────────────────
                Section::make('Detalle de Actividad')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('log_name')
                                ->label('Tipo de log')
                                ->badge()
                                ->color(fn (Activity $record): string => match($record->log_name) {
                                    'api'     => 'warning',
                                    'default' => 'primary',
                                    default   => 'gray',
                                }),
                            TextEntry::make('description')
                                ->label('Descripción'),
                            TextEntry::make('subject_type')
                                ->label('Modelo afectado')
                                ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                            TextEntry::make('subject_id')
                                ->label('ID del registro')
                                ->placeholder('—'),
                            TextEntry::make('causer.name')
                                ->label('Realizado por')
                                ->placeholder('Sistema'),
                            TextEntry::make('created_at')
                                ->label('Fecha y hora')
                                ->dateTime('d/m/Y H:i:s'),
                        ]),
                    ]),

                // ── Sección API: solo cuando log_name = 'api' ─
                Section::make('Detalle de la Llamada API')
                    ->icon('heroicon-o-arrow-path')
                    ->collapsible()
                    ->visible(fn (Activity $record): bool => $record->log_name === 'api')
                    ->schema([
                        Grid::make(2)->schema([

                            TextEntry::make('properties.endpoint')
                                ->label('Endpoint')
                                ->placeholder('—')
                                ->columnSpanFull(),

                            TextEntry::make('properties.ip')
                                ->label('IP del solicitante')
                                ->placeholder('—')
                                ->icon('heroicon-o-globe-alt'),

                            TextEntry::make('properties.resultado')
                                ->label('Resultado')
                                ->placeholder('—')
                                ->badge()
                                ->color(fn (?string $state): string => match($state) {
                                    'ok'                    => 'success',
                                    'error_fecha_faltante',
                                    'error_fecha_invalida'  => 'danger',
                                    default                 => 'gray',
                                }),

                            TextEntry::make('properties.fecha')
                                ->label('Fecha consultada')
                                ->placeholder('—')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('properties.total')
                                ->label('Registros devueltos')
                                ->placeholder('0')
                                ->icon('heroicon-o-document-text'),

                            TextEntry::make('properties.pagina')
                                ->label('Página consultada')
                                ->placeholder('1'),

                            TextEntry::make('properties.desde_cache')
                                ->label('Respondido desde caché')
                                ->formatStateUsing(fn (mixed $state): string => $state ? 'Sí' : 'No')
                                ->badge()
                                ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
                        ]),
                    ]),

                // ── Sección CAMBIOS: solo cuando hay old/attributes ──
                Section::make('Cambios Realizados')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->collapsible()
                    ->visible(fn (Activity $record): bool =>
                        ! empty($record->properties['attributes']) ||
                        ! empty($record->properties['old'])
                    )
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('properties.old')
                                ->label('Valores anteriores')
                                ->formatStateUsing(fn (mixed $state): string =>
                                    static::formatDiff($state, 'old')
                                )
                                ->markdown()
                                ->placeholder('Sin datos anteriores'),

                            TextEntry::make('properties.attributes')
                                ->label('Valores nuevos')
                                ->formatStateUsing(fn (mixed $state): string =>
                                    static::formatDiff($state, 'attributes')
                                )
                                ->markdown()
                                ->placeholder('Sin datos nuevos'),
                        ]),
                    ]),

                // ── Sección EXTRA properties (api con datos extra) ──
                Section::make('Información Adicional')
                    ->icon('heroicon-o-information-circle')
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (Activity $record): bool =>
                        $record->log_name === 'api' &&
                        $record->properties->except(['endpoint','ip','fecha','pagina','total','desde_cache','resultado'])->isNotEmpty()
                    )
                    ->schema([
                        TextEntry::make('properties')
                            ->label('')
                            ->formatStateUsing(function (mixed $state): string {
                                if (!$state instanceof \Illuminate\Support\Collection) {
                                    return '—';
                                }
                                $extra = $state->except(['endpoint','ip','fecha','pagina','total','desde_cache','resultado'])->toArray();
                                return static::formatProperties($extra);
                            })
                            ->markdown()
                            ->columnSpanFull(),
                    ]),

            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs::route('/'),
            'view'  => \App\Filament\Resources\ActivityLogResource\Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}