<?php

declare(strict_types=1);

namespace App\Exports\Sheets;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
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
 * Hoja "Ventas por producto" del Excel del reporte (ver ProductSalesExport).
 *
 * Es FromCollection y no FromArray porque el escritor en cola de Maatwebsite
 * solo reparte en trozos FromCollection / FromQuery / FromView. Declara cola y
 * chunking igual que un export suelto: lo exige QueueContractTest para todo lo
 * que vive en app/Exports, y la hoja se escribe dentro del export en cola.
 *
 * Las cantidades van dos veces: en unidades (número, para sumar y filtrar en
 * Excel) y en cajas + sueltas (texto, como se lee en bodega).
 */
class ProductSalesProductsSheet implements FromCollection, ShouldQueue, WithChunkReading, WithColumnFormatting, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public string $queue = 'reports';

    private const AMOUNT_FORMAT = '#,##0.00';

    private const UNITS_FORMAT = '#,##0';

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
        return 'Ventas por producto';
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function headings(): array
    {
        $filters = $this->report['filters'];
        $warehouses = implode(', ', array_column($this->report['warehouses'], 'code'));

        return [
            [
                'Reporte de ventas por producto — del '.Carbon::parse($filters['date_from'])->format('d/m/Y').
                ' al '.Carbon::parse($filters['date_to'])->format('d/m/Y').
                ' — '.$this->report['date_field_label'].
                ' — Bodegas: '.($warehouses !== '' ? $warehouses : '—').
                ' — Generado '.$this->report['generated_at'],
            ],
            [
                'Código',
                'Producto',
                'Udc.',
                'Unid. por caja',
                'Entró (unid.)',
                'De eso bonificación (unid.)',
                'Devuelto (unid.)',
                'Neto movido (unid.)',
                'Entró (cj + u)',
                'De eso bonificación (cj + u)',
                'Devuelto (cj + u)',
                'Neto movido (cj + u)',
                '% Devolución (unid.)',
                'Facturado (L)',
                'Devuelto (L)',
                'Neto (L)',
            ],
        ];
    }

    /**
     * @return Collection<int, array<int, mixed>>
     */
    public function collection(): Collection
    {
        $rows = [];

        foreach ($this->report['rows'] as $row) {
            $rows[] = [
                $row['product_id'],
                $row['description'],
                $row['unit_sale'] ?? '',
                $row['factor'],
                $row['entered_units'],
                $row['bonus_units'],
                $row['returned_units'],
                $row['net_units'],
                $row['entered']['label'],
                $row['bonus']['label'],
                $row['returned']['label'],
                $row['net']['label'],
                $row['return_pct'] / 100,
                $row['billed_amount'],
                $row['returned_amount'],
                $row['net_amount'],
            ];
        }

        $totals = $this->report['totals'];

        // Totales en lempiras = los de las facturas (valor fiscal), igual que
        // en pantalla; las unidades suman las filas.
        $rows[] = [
            'TOTAL',
            $totals['products_count'].' productos · '.$totals['invoices_count'].' facturas',
            '',
            '',
            array_sum(array_column($this->report['rows'], 'entered_units')),
            array_sum(array_column($this->report['rows'], 'bonus_units')),
            array_sum(array_column($this->report['rows'], 'returned_units')),
            array_sum(array_column($this->report['rows'], 'net_units')),
            $totals['entered']['label'],
            $totals['bonus']['label'],
            $totals['returned']['label'],
            $totals['net']['label'],
            $totals['return_pct'] / 100,
            $totals['billed_total'],
            $totals['returned_total'],
            $totals['net_total'],
        ];

        return collect($rows);
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'D' => self::UNITS_FORMAT,
            'E' => self::UNITS_FORMAT,
            'F' => self::UNITS_FORMAT,
            'G' => self::UNITS_FORMAT,
            'H' => self::UNITS_FORMAT,
            'M' => '0.0%',
            'N' => self::AMOUNT_FORMAT,
            'O' => self::AMOUNT_FORMAT,
            'P' => self::AMOUNT_FORMAT,
        ];
    }

    /**
     * Anchos fijos en vez de autoajuste: la fila 1 lleva el título largo del
     * reporte y el autoajuste haría enorme la columna A.
     *
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 42, 'C' => 8, 'D' => 10,
            'E' => 12, 'F' => 14, 'G' => 12, 'H' => 14,
            'I' => 16, 'J' => 18, 'K' => 16, 'L' => 18,
            'M' => 13, 'N' => 15, 'O' => 15, 'P' => 15,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $lastRow = count($this->report['rows']) + 3;

        return [
            1 => ['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '1B3A6B']]],
            2 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1B3A6B']],
                'alignment' => ['horizontal' => 'center', 'wrapText' => true],
            ],
            $lastRow => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E2E8F0']],
            ],
        ];
    }
}
