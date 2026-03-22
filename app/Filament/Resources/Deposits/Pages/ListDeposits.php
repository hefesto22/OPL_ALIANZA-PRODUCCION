<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Exports\DepositsExport;
use App\Filament\Resources\Deposits\DepositResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Crypt;
use Maatwebsite\Excel\Facades\Excel;

class ListDeposits extends ListRecords
{
    protected static string $resource = DepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

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
                    ])
                    ->modalHeading('Reporte de Depósitos — PDF')
                    ->modalDescription('Seleccioná el período para generar el reporte agrupado por banco.')
                    ->modalSubmitActionLabel('Generar Reporte')
                    ->action(function (array $data): void {
                        $payload = Crypt::encryptString(json_encode([
                            'date_from' => $data['date_from'] ?? null,
                            'date_to'   => $data['date_to']   ?? null,
                        ]));

                        $this->js("window.open('/imprimir/reportes/depositos?payload=" . urlencode($payload) . "', '_blank')");
                    }),

                // ── Export Excel ───────────────────────────────────────
                Action::make('export_excel')
                    ->label('Exportar Excel')
                    ->icon('heroicon-o-table-cells')
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
                    ])
                    ->modalHeading('Exportar Depósitos — Excel')
                    ->modalDescription('Seleccioná el período para exportar.')
                    ->modalSubmitActionLabel('Exportar')
                    ->action(function (array $data): mixed {
                        $filename = 'depositos_' . now()->format('Y-m-d') . '.xlsx';

                        return Excel::download(
                            new DepositsExport(
                                dateFrom: $data['date_from'] ?? null,
                                dateTo:   $data['date_to']   ?? null,
                            ),
                            $filename
                        );
                    }),
            ])
                ->label('Reportes')
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray'),
        ];
    }
}