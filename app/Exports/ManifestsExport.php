<?php

namespace App\Exports;

use App\Models\Manifest;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ManifestsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private readonly ?string $status = null,
        private readonly ?string $dateFrom = null,
        private readonly ?string $dateTo = null,
    ) {}

    public function query(): Builder
    {
        $query = Manifest::query()->with(['warehouse']);

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->dateFrom) {
            $query->whereDate('date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('date', '<=', $this->dateTo);
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
