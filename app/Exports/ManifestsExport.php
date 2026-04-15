<?php

namespace App\Exports;

use App\Models\Manifest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export de manifiestos — una fila por manifiesto (11 columnas).
 *
 * Se procesa en background (ShouldQueue) para no bloquear el request
 * del usuario. El usuario recibe notificación cuando el archivo está
 * listo (ver chain en ListManifests::export_excel action).
 *
 * Cola: `reports` (ver config/horizon.php, supervisor-reports).
 */
class ManifestsExport implements
    FromQuery,
    ShouldAutoSize,
    ShouldQueue,
    WithChunkReading,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithTitle
{
    use Exportable;

    public string $queue = 'reports';

    /**
     * @param  ?int  $warehouseId  Filtro de bodega. `null` = ver todas las bodegas
     *                             (solo super_admin/admin). Se captura en el call
     *                             site con WarehouseScope::getWarehouseId() porque
     *                             el job corre en worker sin contexto de Auth.
     */
    public function __construct(
        private readonly ?string $status = null,
        private readonly ?string $dateFrom = null,
        private readonly ?string $dateTo = null,
        private readonly ?int $warehouseId = null,
    ) {}

    public function chunkSize(): int
    {
        return 1000;
    }

    public function query(): Builder
    {
        // Columnas específicas en el relation — bodega solo necesita code.
        $query = Manifest::query()->with(['warehouse:id,code']);

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->dateFrom) {
            $query->whereDate('date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('date', '<=', $this->dateTo);
        }

        // Multi-tenant: usuarios de bodega solo ven su bodega.
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }

        return $query->orderBy('date', 'desc');
    }

    public function headings(): array
    {
        return [
            '# Manifiesto',
            'Fecha',
            'Estado',
            'Bodega',
            'Facturas',
            'Total Manifiesto (HNL)',
            'Total Devoluciones (HNL)',
            'A Depositar (HNL)',
            'Depositado (HNL)',
            'Diferencia (HNL)',
            'Fecha Cierre',
        ];
    }

    public function map($manifest): array
    {
        return [
            $manifest->number,
            $manifest->date ? \Carbon\Carbon::parse($manifest->date)->format('d/m/Y') : '—',
            match ($manifest->status) {
                'pending' => 'Pendiente',
                'processing' => 'Procesando',
                'imported' => 'Importado',
                'closed' => 'Cerrado',
                default => $manifest->status,
            },
            $manifest->warehouse?->code ?? '—',
            $manifest->invoices_count,
            number_format($manifest->total_invoices, 2),
            number_format($manifest->total_returns, 2),
            number_format($manifest->total_to_deposit, 2),
            number_format($manifest->total_deposited, 2),
            number_format($manifest->difference, 2),
            $manifest->closed_at ? \Carbon\Carbon::parse($manifest->closed_at)->format('d/m/Y H:i') : '—',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1B3A6B']],
                'alignment' => ['horizontal' => 'center'],
            ],
        ];
    }

    public function title(): string
    {
        return 'Manifiestos';
    }
}
