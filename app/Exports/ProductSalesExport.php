<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Sheets\ProductSalesProductsSheet;
use App\Exports\Sheets\ProductSalesTransfersSheet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del reporte de ventas por producto: hoja "Ventas por producto" y hoja
 * "Traslados".
 *
 * Recibe el reporte YA armado por ProductSalesReportService (el mismo que se
 * ve en pantalla, sacado de la caché), así que no vuelve a consultar la base.
 *
 * Va a la cola `reports` como todos los exports (contrato fijado en
 * QueueContractTest) y avisa con NotifyExportReady cuando el archivo está
 * listo.
 */
class ProductSalesExport implements ShouldQueue, WithChunkReading, WithMultipleSheets
{
    use Exportable;

    public string $queue = 'reports';

    /**
     * @param  array<string, mixed>  $report  Salida de ProductSalesReportService::generate().
     */
    public function __construct(private readonly array $report) {}

    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new ProductSalesProductsSheet($this->report),
            new ProductSalesTransfersSheet($this->report),
        ];
    }
}
