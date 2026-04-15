<?php

namespace App\Filament\Resources\Manifests\Pages;

use App\Exports\ManifestsExport;
use App\Filament\Resources\Manifests\ManifestResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Maatwebsite\Excel\Facades\Excel;

class ListManifests extends ListRecords
{
    protected static string $resource = ManifestResource::class;

    protected static ?string $title = 'Manifiestos';

    // ── Tabs de estado ─────────────────────────────────────────────────────
    public function getTabs(): array
    {
        $baseQuery = ManifestResource::getEloquentQuery();

        return [
            'activos' => Tab::make('Activos')
                ->icon('heroicon-o-arrow-path')
                ->badge(
                    (clone $baseQuery)
                        ->whereIn('status', ['pending', 'processing', 'imported'])
                        ->count()
                )
                ->badgeColor('warning')
                ->modifyQueryUsing(
                    fn (Builder $query) => $query->whereIn('status', ['pending', 'processing', 'imported'])
                ),

            'cerrados' => Tab::make('Cerrados')
                ->icon('heroicon-o-lock-closed')
                ->badge(
                    (clone $baseQuery)
                        ->where('status', 'closed')
                        ->count()
                )
                ->badgeColor('success')
                ->modifyQueryUsing(
                    fn (Builder $query) => $query->where('status', 'closed')
                ),
        ];
    }

    /**
     * Convierte el tipo de período seleccionado en el modal a un par
     * [date_from, date_to] listo para pasar al reporte o al export.
     */
    private function resolvePeriodDates(array $data): array
    {
        return match ($data['period_type'] ?? null) {
            'today' => [
                'date_from' => today()->toDateString(),
                'date_to' => today()->toDateString(),
            ],
            'week' => [
                'date_from' => now()->startOfWeek()->toDateString(),
                'date_to' => now()->endOfWeek()->toDateString(),
            ],
            'month' => [
                'date_from' => now()->startOfMonth()->toDateString(),
                'date_to' => now()->endOfMonth()->toDateString(),
            ],
            'date' => [
                'date_from' => $data['specific_date'] ?? null,
                'date_to' => $data['specific_date'] ?? null,
            ],
            'custom' => [
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
            ],
            default => ['date_from' => null, 'date_to' => null],
        };
    }

    /**
     * Schema de período reutilizable en todos los modales.
     * Select predefinido + campos condicionales según la opción elegida.
     * $withDateField = true agrega selector "¿Filtrar por qué fecha?" (útil en reporte bodega).
     */
    private static function periodSchema(bool $withDateField = false): array
    {
        $fields = [
            Select::make('period_type')
                ->label('Período')
                ->placeholder('Todos los manifiestos')
                ->options([
                    'today' => 'Hoy',
                    'week' => 'Esta semana',
                    'month' => 'Este mes',
                    'date' => 'Fecha específica',
                    'custom' => 'Rango personalizado',
                ])
                ->live(),

            DatePicker::make('specific_date')
                ->label('Fecha')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->visible(fn (Get $get) => $get('period_type') === 'date'),

            DatePicker::make('date_from')
                ->label('Desde')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->visible(fn (Get $get) => $get('period_type') === 'custom'),

            DatePicker::make('date_to')
                ->label('Hasta')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->visible(fn (Get $get) => $get('period_type') === 'custom'),

            Select::make('status')
                ->label('Estado')
                ->placeholder('Todos los estados')
                ->options([
                    'imported' => 'Importado',
                    'closed' => 'Cerrado',
                ]),
        ];

        // Campo extra: ¿por qué fecha se filtra? Solo para reporte por bodega.
        if ($withDateField) {
            $fields[] = Select::make('date_field')
                ->label('Filtrar por fecha de')
                ->options([
                    'date' => 'Fecha del manifiesto (Jaremar)',
                    'closed_at' => 'Fecha de cierre (Hozana)',
                ])
                ->default('date')
                ->helperText('Usa "Fecha del manifiesto" para ventas del período; "Fecha de cierre" para control contable.');
        }

        return $fields;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Subir Manifiesto')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(function (): bool {
                    /** @var \App\Models\User $user */
                    $user = Auth::user();

                    return $user->hasAnyRole(['super_admin', 'admin']);
                }),

            ActionGroup::make([
                // ── Reporte PDF ────────────────────────────────────────
                Action::make('report_pdf')
                    ->label('Ver Reporte PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('danger')
                    ->schema(self::periodSchema())
                    ->modalHeading('Reporte de Manifiestos — PDF')
                    ->modalDescription('Seleccioná el período y filtros para generar el reporte.')
                    ->modalSubmitActionLabel('Generar Reporte')
                    ->action(function (array $data): void {
                        ['date_from' => $from, 'date_to' => $to] = $this->resolvePeriodDates($data);

                        $payload = Crypt::encryptString(json_encode([
                            'date_from' => $from,
                            'date_to' => $to,
                            'status' => $data['status'] ?? null,
                        ]));

                        $this->js("window.open('/imprimir/reportes/manifiestos?payload=".urlencode($payload)."', '_blank')");
                    }),

                // ── Reporte PDF Sin ISV ────────────────────────────────
                Action::make('report_pdf_sin_isv')
                    ->label('Ver Reporte Sin ISV')
                    ->icon('heroicon-o-document-minus')
                    ->color('warning')
                    ->schema(self::periodSchema())
                    ->modalHeading('Reporte de Manifiestos — Sin ISV')
                    ->modalDescription('Seleccioná el período y filtros para generar el reporte con valores netos sin ISV.')
                    ->modalSubmitActionLabel('Generar Reporte')
                    ->action(function (array $data): void {
                        ['date_from' => $from, 'date_to' => $to] = $this->resolvePeriodDates($data);

                        $payload = Crypt::encryptString(json_encode([
                            'date_from' => $from,
                            'date_to' => $to,
                            'status' => $data['status'] ?? null,
                        ]));

                        $this->js("window.open('/imprimir/reportes/manifiestos-sin-isv?payload=".urlencode($payload)."', '_blank')");
                    }),

                // ── Reporte por Bodega ─────────────────────────────────
                Action::make('report_por_bodega')
                    ->label('Reporte por Bodega')
                    ->icon('heroicon-o-building-office-2')
                    ->color('info')
                    ->schema(self::periodSchema(withDateField: true))
                    ->modalHeading('Reporte de Ventas por Bodega')
                    ->modalDescription('Compara facturación, devoluciones y venta neta de cada bodega en el período seleccionado.')
                    ->modalSubmitActionLabel('Generar Reporte')
                    ->action(function (array $data): void {
                        ['date_from' => $from, 'date_to' => $to] = $this->resolvePeriodDates($data);

                        $payload = Crypt::encryptString(json_encode([
                            'date_from' => $from,
                            'date_to' => $to,
                            'status' => $data['status'] ?? null,
                            'date_field' => $data['date_field'] ?? 'date',
                        ]));

                        $this->js("window.open('/imprimir/reportes/ventas-por-bodega?payload=".urlencode($payload)."', '_blank')");
                    }),

                // ── Export Excel ───────────────────────────────────────
                Action::make('export_excel')
                    ->label('Exportar Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->schema(self::periodSchema())
                    ->modalHeading('Exportar Manifiestos — Excel')
                    ->modalDescription('Seleccioná el período y filtros para exportar.')
                    ->modalSubmitActionLabel('Exportar')
                    ->action(function (array $data): mixed {
                        ['date_from' => $from, 'date_to' => $to] = $this->resolvePeriodDates($data);

                        $filename = 'manifiestos_'.now()->format('Y-m-d').'.xlsx';

                        return Excel::download(
                            new ManifestsExport(
                                status: $data['status'] ?? null,
                                dateFrom: $from,
                                dateTo: $to,
                            ),
                            $filename
                        );
                    }),
            ])
                ->label('Reportes')
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray')
                ->visible(function (): bool {
                    /** @var \App\Models\User $user */
                    $user = Auth::user();

                    return $user->hasAnyRole(['super_admin', 'admin']);
                }),
        ];
    }
}
