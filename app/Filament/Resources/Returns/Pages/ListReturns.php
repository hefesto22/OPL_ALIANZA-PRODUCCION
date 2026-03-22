<?php

namespace App\Filament\Resources\Returns\Pages;

use App\Exports\ReturnsDetailExport;
use App\Exports\ReturnsExport;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\Returns\Tables\ReturnsTable;
use App\Models\InvoiceReturn;
use App\Models\Warehouse;
use App\Services\ReturnExportService;
use App\Services\ReturnExporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Crypt;
use Maatwebsite\Excel\Facades\Excel;

class ListReturns extends ListRecords
{
    protected static string $resource = ReturnResource::class;

    protected static ?string $title = 'Devoluciones';

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
                                'pending'  => 'Pendiente',
                                'approved' => 'Aprobada',
                                'rejected' => 'Rechazada',
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
                    ->label('Exportar Excel')
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
                                'pending'  => 'Pendiente',
                                'approved' => 'Aprobada',
                                'rejected' => 'Rechazada',
                            ]),

                        Select::make('warehouse_id')
                            ->label('Bodega')
                            ->placeholder('Todas las bodegas')
                            ->options(
                                Warehouse::where('is_active', true)
                                    ->pluck('name', 'id')
                            ),
                    ])
                    ->modalHeading('Exportar Devoluciones — Excel')
                    ->modalDescription('Seleccioná el período y filtros para exportar.')
                    ->modalSubmitActionLabel('Exportar')
                    ->action(function (array $data): mixed {
                        $filename = 'devoluciones_' . now()->format('Y-m-d') . '.xlsx';

                        return Excel::download(
                            new ReturnsExport(
                                status:      $data['status']       ?? null,
                                warehouseId: $data['warehouse_id'] ?? null,
                                dateFrom:    $data['date_from']    ?? null,
                                dateTo:      $data['date_to']      ?? null,
                            ),
                            $filename
                        );
                    }),

                // ── Exportación detallada línea por línea (formato Jaremar) ───
                Action::make('export_excel_detail')
                    ->label('Exportar Excel (Formato Jaremar)')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
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
                                'pending'  => 'Pendiente',
                                'approved' => 'Aprobada',
                                'rejected' => 'Rechazada',
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
                    ->action(function (array $data): mixed {
                        $from = $data['date_from'] ?? null;
                        $to   = $data['date_to']   ?? null;

                        $label    = $from && $to
                            ? \Carbon\Carbon::parse($from)->format('d-m-Y') . ' HASTA ' . \Carbon\Carbon::parse($to)->format('d-m-Y')
                            : now()->format('Y-m-d');
                        $filename = "Devoluciones_{$label}.xlsx";

                        return Excel::download(
                            new ReturnsDetailExport(
                                dateFrom:    $from,
                                dateTo:      $to,
                                status:      $data['status']       ?? null,
                                warehouseId: $data['warehouse_id'] ? (int) $data['warehouse_id'] : null,
                            ),
                            $filename
                        );
                    }),

                // ── Formato Jaremar ────────────────────────────────────
                Action::make('export_json')
                    ->label('Exportar JSON (Jaremar)')
                    ->icon('heroicon-o-code-bracket')
                    ->color('gray')
                    ->action(fn () => $this->exportReturnsJaremar('json')),

                Action::make('export_xml')
                    ->label('Exportar XML (Jaremar)')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->action(fn () => $this->exportReturnsJaremar('xml')),

                Action::make('export_csv')
                    ->label('Exportar CSV (Jaremar)')
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->action(fn () => $this->exportReturnsJaremar('csv')),
            ])
                ->label('Reportes')
                ->icon('heroicon-o-document-chart-bar')
                ->color('info'),
        ];
    }

    private function exportReturnsJaremar(string $format): mixed
    {
        $exportService = app(ReturnExportService::class);
        $exporter      = app(ReturnExporter::class);

        $query   = InvoiceReturn::query();
        $returns = $exportService->withRelations($query)->get();

        $data     = $exportService->toJaremarArray($returns);
        $filename = 'devoluciones_' . now()->format('Y-m-d');

        return match ($format) {
            'json' => $exporter->toJson($data, "{$filename}.json"),
            'xml'  => $exporter->toXml($data, "{$filename}.xml"),
            'csv'  => $exporter->toCsv($data, "{$filename}.csv"),
        };
    }
}