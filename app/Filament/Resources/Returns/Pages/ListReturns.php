<?php

namespace App\Filament\Resources\Returns\Pages;

use App\Exports\ReturnsDetailExport;
use App\Exports\ReturnsExport;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\Returns\Tables\ReturnsTable;
use App\Jobs\NotifyExportReady;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class ListReturns extends ListRecords
{
    protected static string $resource = ReturnResource::class;

    protected static ?string $title = 'Devoluciones';

    // ── Tabs de estado ─────────────────────────────────────────────────────
    public function getTabs(): array
    {
        $baseQuery = ReturnResource::getEloquentQuery();

        return [
            'activas' => Tab::make('Activas')
                ->icon('heroicon-o-check-circle')
                ->badge(
                    (clone $baseQuery)
                        ->whereIn('status', ['pending', 'approved'])
                        ->count()
                )
                ->badgeColor('success')
                ->modifyQueryUsing(
                    fn (Builder $query) => $query->whereIn('status', ['pending', 'approved'])
                ),

            'canceladas' => Tab::make('Canceladas')
                ->icon('heroicon-o-x-circle')
                ->badge(
                    (clone $baseQuery)
                        ->where('status', 'cancelled')
                        ->count()
                )
                ->badgeColor('gray')
                ->modifyQueryUsing(
                    fn (Builder $query) => $query->where('status', 'cancelled')
                ),

            'todas' => Tab::make('Todas')
                ->icon('heroicon-o-list-bullet'),
        ];
    }

    public function table(Table $table): Table
    {
        return ReturnsTable::make($table);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva Devolución'),

            ActionGroup::make([
                // ── Reporte PDF ────────────────────────────────────────
                Action::make('report_pdf')
                    ->label('Ver Reporte PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('danger')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Fecha desde')
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        DatePicker::make('date_to')
                            ->label('Fecha hasta')
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        Select::make('status')
                            ->label('Estado')
                            ->placeholder('Todos los estados')
                            ->options([
                                'pending'   => 'Pendiente',
                                'approved'  => 'Aprobada',
                                'rejected'  => 'Rechazada',
                                'cancelled' => 'Cancelada',
                            ]),

                        Select::make('warehouse_id')
                            ->label('Bodega')
                            ->placeholder('Todas las bodegas')
                            ->options(
                                Warehouse::where('is_active', true)
                                    ->pluck('name', 'id')
                            ),
                    ])
                    ->modalHeading('Reporte de Devoluciones — PDF')
                    ->modalDescription('Seleccioná el período y filtros para generar el reporte agrupado por manifiesto.')
                    ->modalSubmitActionLabel('Generar Reporte')
                    ->action(function (array $data): void {
                        $payload = Crypt::encryptString(json_encode([
                            'date_from'    => $data['date_from']    ?? null,
                            'date_to'      => $data['date_to']      ?? null,
                            'status'       => $data['status']       ?? null,
                            'warehouse_id' => $data['warehouse_id'] ?? null,
                        ]));

                        $this->js("window.open('/imprimir/reportes/devoluciones?payload=" . urlencode($payload) . "', '_blank')");
                    }),

                // ── Export Excel ───────────────────────────────────────
                Action::make('export_excel')
                    ->label('Excel Interno — Resumen simple')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('success')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Fecha desde')
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        DatePicker::make('date_to')
                            ->label('Fecha hasta')
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        Select::make('status')
                            ->label('Estado')
                            ->placeholder('Todos los estados')
                            ->options([
                                'pending'   => 'Pendiente',
                                'approved'  => 'Aprobada',
                                'rejected'  => 'Rechazada',
                                'cancelled' => 'Cancelada',
                            ]),

                        Select::make('warehouse_id')
                            ->label('Bodega')
                            ->placeholder('Todas las bodegas')
                            ->options(
                                Warehouse::where('is_active', true)
                                    ->pluck('name', 'id')
                            ),
                    ])
                    ->modalHeading('Exportar Devoluciones — Reporte Interno')
                    ->modalDescription('Seleccioná el período y filtros. Se generará en segundo plano y te notificaremos cuando esté listo.')
                    ->modalSubmitActionLabel('Exportar')
                    ->action(function (array $data): void {
                        $fileName = 'devoluciones_interno_' . now()->format('Y-m-d') . '.xlsx';
                        $filePath = "exports/{$fileName}";

                        (new ReturnsExport(
                            status:      $data['status']       ?? null,
                            warehouseId: $data['warehouse_id'] ?? null,
                            dateFrom:    $data['date_from']    ?? null,
                            dateTo:      $data['date_to']      ?? null,
                        ))->queue($filePath, 'local')->chain([
                            new NotifyExportReady(
                                userId:   Auth::id(),
                                filePath: $filePath,
                                fileName: $fileName,
                            ),
                        ]);

                        Notification::make()
                            ->title('Exportación en proceso')
                            ->body("El archivo {$fileName} se está generando. Te notificaremos cuando esté listo.")
                            ->info()
                            ->send();
                    }),

                // ── Exportación detallada línea por línea (formato Jaremar) ───
                Action::make('export_excel_detail')
                    ->label('Excel Jaremar — 71 columnas')
                    ->icon('heroicon-o-table-cells')
                    ->color('warning')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Fecha desde')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->required(),

                        DatePicker::make('date_to')
                            ->label('Fecha hasta')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->required(),

                        Select::make('status')
                            ->label('Estado')
                            ->placeholder('Todos los estados')
                            ->options([
                                'pending'   => 'Pendiente',
                                'approved'  => 'Aprobada',
                                'rejected'  => 'Rechazada',
                                'cancelled' => 'Cancelada',
                            ]),

                        Select::make('warehouse_id')
                            ->label('Bodega')
                            ->placeholder('Todas las bodegas')
                            ->options(
                                Warehouse::where('is_active', true)
                                    ->pluck('name', 'id')
                            ),
                    ])
                    ->modalHeading('Exportar Devoluciones — Formato Jaremar (71 columnas)')
                    ->modalDescription('Genera un Excel con el mismo formato de 71 columnas que provee Jaremar. Cada fila representa una línea de devolución.')
                    ->modalSubmitActionLabel('Exportar')
                    ->action(function (array $data): void {
                        $from = $data['date_from'] ?? null;
                        $to   = $data['date_to']   ?? null;

                        $label    = $from && $to
                            ? \Carbon\Carbon::parse($from)->format('d-m-Y') . ' HASTA ' . \Carbon\Carbon::parse($to)->format('d-m-Y')
                            : now()->format('Y-m-d');
                        $fileName = "Devoluciones_{$label}.xlsx";
                        $filePath = "exports/{$fileName}";

                        // Despachar a cola — ReturnsDetailExport implementa ShouldQueue
                        (new ReturnsDetailExport(
                            dateFrom:    $from,
                            dateTo:      $to,
                            status:      $data['status']       ?? null,
                            warehouseId: isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
                        ))->queue($filePath, 'local')->chain([
                            new NotifyExportReady(
                                userId:   Auth::id(),
                                filePath: $filePath,
                                fileName: $fileName,
                            ),
                        ]);

                        Notification::make()
                            ->title('Exportación en proceso')
                            ->body("El archivo {$fileName} se está generando. Te notificaremos cuando esté listo.")
                            ->info()
                            ->send();
                    }),

            ])
                ->label('Reportes')
                ->icon('heroicon-o-document-chart-bar')
                ->color('info'),
        ];
    }

}