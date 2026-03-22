<?php

namespace App\Exports;

use App\Models\Deposit;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DepositsExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly ?string $dateFrom = null,
        private readonly ?string $dateTo = null,
    ) {}

    public function query(): Builder
    {
        $query = Deposit::query()->with(['manifest', 'createdBy']);

        if ($this->dateFrom) {
            $query->whereDate('deposit_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('deposit_date', '<=', $this->dateTo);
        }

        return $query->orderBy('deposit_date', 'desc');
    }

    public function headings(): array
    {
        return [
            '# Depósito',
            '# Manifiesto',
            'Estado Manifiesto',
            'Fecha Depósito',
            'Monto (HNL)',
            'Banco',
            'Referencia',
            'Notas',
            'Registrado por',
            'Fecha Registro',
        ];
    }

    public function map($deposit): array
    {
        return [
            $deposit->id,
            $deposit->manifest?->number ?? '—',
            match($deposit->manifest?->status) {
                'pending'    => 'Pendiente',
                'processing' => 'Procesando',
                'imported'   => 'Importado',
                'closed'     => 'Cerrado',
                default      => $deposit->manifest?->status ?? '—',
            },
            $deposit->deposit_date ? \Carbon\Carbon::parse($deposit->deposit_date)->format('d/m/Y') : '—',
            number_format($deposit->amount, 2),
            $deposit->bank ?? '—',
            $deposit->reference ?? '—',
            $deposit->notes ?? '—',
            $deposit->createdBy?->name ?? '—',
            $deposit->created_at ? \Carbon\Carbon::parse($deposit->created_at)->format('d/m/Y H:i') : '—',
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
        return 'Depósitos';
    }
}