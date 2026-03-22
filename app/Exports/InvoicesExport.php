<?php

namespace App\Exports;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InvoicesExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly int $manifestId,
        private readonly string $manifestNumber,
    ) {}

    public function query(): Builder
    {
        return Invoice::query()
            ->with(['warehouse'])
            ->where('manifest_id', $this->manifestId)
            ->where('status', '!=', 'rejected')
            ->orderBy('route_number')
            ->orderBy('invoice_number');
    }

    public function headings(): array
    {
        return [
            '# Factura',
            'Fecha Factura',
            'Almacén',
            'Ruta',
            'Cód. Cliente',
            'Cliente',
            'RTN Cliente',
            'Municipio',
            'Departamento',
            'Dirección',
            'Teléfono',
            'Tipo Pago',
            'Estado',
            'Total (HNL)',
            'ISV 18%',
            'ISV 15%',
        ];
    }

    public function map($invoice): array
    {
        return [
            $invoice->invoice_number,
            $invoice->invoice_date ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') : '—',
            $invoice->warehouse?->code ?? '—',
            $invoice->route_number,
            $invoice->client_id,
            $invoice->client_name,
            $invoice->client_rtn,
            $invoice->municipality,
            $invoice->department,
            $invoice->address,
            $invoice->phone,
            $invoice->payment_type,
            match($invoice->status) {
                'imported'       => 'Importada',
                'partial_return' => 'Dev. Parcial',
                'returned'       => 'Devuelta',
                'rejected'       => 'Rechazada',
                default          => $invoice->status,
            },
            number_format($invoice->total, 2),
            number_format($invoice->isv18, 2),
            number_format($invoice->isv15, 2),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '1B3A6B']],
                'alignment' => ['horizontal' => 'center'],
            ],
        ];
    }

    public function title(): string
    {
        return "Facturas #{$this->manifestNumber}";
    }
}