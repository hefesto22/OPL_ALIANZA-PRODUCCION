<?php

namespace App\Exports;

use App\Models\InvoiceReturn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Exportación resumida de devoluciones — una fila por devolución (12 columnas).
 *
 * Formato interno para revisión rápida de datos. Para el formato línea por
 * línea (71 columnas) que Jaremar acepta como entrada, ver ReturnsDetailExport.
 *
 * Chunking: 1000 filas por iteración — las 12 columnas cargan poco por fila,
 * un chunk más generoso que el detalle es aceptable.
 *
 * Cola: `reports` (misma que los demás exports pesados).
 */
class ReturnsExport implements FromQuery, ShouldAutoSize, ShouldQueue, WithChunkReading, WithEvents, WithHeadings, WithMapping, WithTitle
{
    use Exportable;

    /**
     * Cola dedicada a jobs pesados de reportería.
     */
    public string $queue = 'reports';

    /**
     * @param  ?int  $warehouseId  Filtro de bodega. `null` = ver todas
     *                             (super_admin/admin). Se pasa desde el call
     *                             site con WarehouseScope::getWarehouseId()
     *                             porque el worker no tiene Auth::user().
     *                             Cambiado de `?string` a `?int` para tipado
     *                             correcto y serialización estable.
     */
    public function __construct(
        private readonly ?string $status = null,
        private readonly ?int $warehouseId = null,
        private readonly ?string $dateFrom = null,
        private readonly ?string $dateTo = null,
    ) {}

    public function chunkSize(): int
    {
        return 1000;
    }

    public function query(): Builder
    {
        // `createdBy` estaba en map() pero NO en with() → N+1 oculto.
        // Lo agregamos acá con columnas específicas (no necesitamos todo
        // el User — solo name para el reporte).
        $query = InvoiceReturn::query()
            ->with([
                'invoice:id,invoice_number',
                'manifest:id,number',
                'warehouse:id,code',
                'returnReason:id,code',
                'createdBy:id,name',
            ]);

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }

        if ($this->dateFrom) {
            $query->whereDate('return_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('return_date', '<=', $this->dateTo);
        }

        return $query->orderBy('return_date', 'desc');
    }

    public function headings(): array
    {
        return [
            '# Devolución',
            '# Factura',
            '# Manifiesto',
            'Bodega',
            'Cliente',
            'Motivo',
            'Tipo',
            'Estado',
            'Total (HNL)',
            'Fecha Devolución',
            'Fecha Procesado',
            'Registrado por',
        ];
    }

    public function map($return): array
    {
        return [
            $return->id,
            $return->invoice?->invoice_number ?? '—',
            $return->manifest?->number ?? '—',
            $return->warehouse?->code ?? '—',
            $return->client_name,
            $return->returnReason?->code ?? '—',
            match ($return->type) {
                'total' => 'Total',
                'partial' => 'Parcial',
                default => $return->type,
            },
            match ($return->status) {
                'pending' => 'Pendiente',
                'approved' => 'Aprobada',
                'rejected' => 'Rechazada',
                'cancelled' => 'Cancelada',
                default => $return->status,
            },
            number_format($return->total, 2),
            $return->return_date ? \Carbon\Carbon::parse($return->return_date)->format('d/m/Y') : '—',
            $return->processed_date ? \Carbon\Carbon::parse($return->processed_date)->format('d/m/Y') : '—',
            $return->createdBy?->name ?? '—',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1:L1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1A7A4A']],
                    'alignment' => ['horizontal' => 'center'],
                ]);
            },
        ];
    }

    public function title(): string
    {
        return 'Devoluciones';
    }
}
