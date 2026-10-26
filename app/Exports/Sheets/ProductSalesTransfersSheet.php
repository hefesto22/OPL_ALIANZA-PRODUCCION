<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Traslados" del Excel del reporte de ventas por producto.
 *
 * Una fila por devolución de cada factura trasladada (una sola fila si la
 * factura no tiene devoluciones), para poder filtrar en Excel por bodega,
 * usuario o momento. Los datos vienen armados por ProductSalesTransfersSection.
 */
class ProductSalesTransfersSheet implements FromCollection, ShouldQueue, WithChunkReading, WithColumnFormatting, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public string $queue = 'reports';

    /**
     * @param  array<string, mixed>  $report  Salida de ProductSalesReportService::generate().
     */
    public function __construct(private readonly array $report) {}

    public function chunkSize(): int
    {
        return 500;
    }

    public function title(): string
    {
        return 'Traslados';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Dirección',
            'Factura',
            'Fecha factura',
            'Cliente',
            'Manifiesto',
            'Traslado',
            'Trasladada el',
            'Trasladó',
            'Motivo',
            'Total factura (L)',
            'Devolución registrada',
            'Registró',
            'Bodega del usuario',
            'La factura estaba en',
            'Momento',
            'Tipo',
            'Estado',
            'Productos devueltos',
            'Monto devolución (L)',
        ];
    }

    /**
     * @return Collection<int, array<int, mixed>>
     */
    public function collection(): Collection
    {
        $rows = [];
        $transfers = $this->report['transfers'];

        foreach (['received' => 'Llegó a esta bodega', 'sent' => 'Salió a otra bodega'] as $key => $direction) {
            foreach ($transfers[$key] as $invoice) {
                $lastMove = end($invoice['moves']) ?: ['at' => '', 'by' => '', 'reason' => ''];

                $base = [
                    $direction,
                    $invoice['invoice_number'],
                    $invoice['invoice_date'],
                    $invoice['client'],
                    $invoice['manifest_number'],
                    $invoice['chain'],
                    $lastMove['at'],
                    $lastMove['by'],
                    $lastMove['reason'],
                    $invoice['total'],
                ];

                if ($invoice['returns'] === []) {
                    $rows[] = [...$base, 'Sin devoluciones', '', '', '', '', '', '', '', null];

                    continue;
                }

                foreach ($invoice['returns'] as $return) {
                    $rows[] = [
                        ...$base,
                        $return['registered_at'],
                        $return['user'],
                        $return['user_warehouses'],
                        $return['registered_in'],
                        $return['timing'],
                        $return['type'],
                        $return['status'].($return['counts'] ? '' : ' (no cuenta)'),
                        implode("\n", array_map(
                            fn (array $line): string => $line['description'].' — '.$line['quantity'],
                            $return['lines'],
                        )),
                        $return['total'],
                    ];
                }
            }
        }

        if ($rows === []) {
            $rows[] = ['Ninguna factura de este período cambió de bodega.'];
        }

        return collect($rows);
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'J' => '#,##0.00',
            'S' => '#,##0.00',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return [
            'A' => 20, 'B' => 22, 'C' => 12, 'D' => 34, 'E' => 12,
            'F' => 16, 'G' => 16, 'H' => 18, 'I' => 40, 'J' => 15,
            'K' => 18, 'L' => 20, 'M' => 16, 'N' => 14, 'O' => 22,
            'P' => 9, 'Q' => 20, 'R' => 50, 'S' => 15,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1B3A6B']],
                'alignment' => ['horizontal' => 'center', 'wrapText' => true],
            ],
        ];
    }
}
