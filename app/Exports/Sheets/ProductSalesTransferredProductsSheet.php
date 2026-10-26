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
 * Hoja "Productos trasladados": una fila por producto de cada factura que
 * cambió de bodega, con lo devuelto y quién lo devolvió. Los datos vienen
 * armados por ProductSalesTransfersSection.
 */
class ProductSalesTransferredProductsSheet implements FromCollection, ShouldQueue, WithChunkReading, WithColumnFormatting, WithColumnWidths, WithHeadings, WithStyles, WithTitle
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
        return 'Productos trasladados';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Dirección',
            'Factura',
            'Traslado',
            'Código',
            'Producto',
            'Cantidad (unid.)',
            'Cantidad (cj + u)',
            'Devuelto (unid.)',
            'Devuelto (cj + u)',
            'Quién devolvió',
            'Neto (cj + u)',
            'Monto (L)',
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
                foreach ($invoice['products'] as $product) {
                    $rows[] = [
                        $direction,
                        $invoice['invoice_number'],
                        $invoice['chain'],
                        $product['product_id'],
                        $product['description'].($product['bonus'] ? ' (bonificación)' : ''),
                        $product['units'],
                        $product['quantity'],
                        $product['returned_units'],
                        $product['returned'],
                        implode("\n", array_map(
                            fn (array $who): string => "{$who['user']} ({$who['user_warehouses']}) — {$who['quantity']}"
                                ." · en {$who['registered_in']} · {$who['registered_at']} · ".mb_strtolower($who['timing']),
                            $product['returned_by'],
                        )),
                        $product['net'],
                        $product['amount'],
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
            'F' => '#,##0',
            'H' => '#,##0',
            'L' => '#,##0.00',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return [
            'A' => 20, 'B' => 22, 'C' => 14, 'D' => 12, 'E' => 40, 'F' => 12,
            'G' => 16, 'H' => 12, 'I' => 16, 'J' => 60, 'K' => 16, 'L' => 14,
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
