<?php

namespace App\Exports;

use App\Models\Deposit;
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
 * Export de depósitos — una fila por depósito (10 columnas).
 *
 * Se procesa en background (ShouldQueue) para no bloquear el request
 * del usuario. El usuario recibe notificación cuando el archivo está
 * listo (ver chain en ListDeposits::export_excel action).
 *
 * Cola: `reports` (ver config/horizon.php, supervisor-reports).
 */
class DepositsExport implements FromQuery, ShouldAutoSize, ShouldQueue, WithChunkReading, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use Exportable;

    public string $queue = 'reports';

    /**
     * @param  ?int  $warehouseId  Filtro de bodega vía manifest.warehouse_id.
     *                             `null` = ver todas las bodegas (super_admin/admin).
     *                             Los depósitos no tienen warehouse_id propio; por
     *                             eso filtramos via whereHas('manifest').
     */
    public function __construct(
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
        // Columnas específicas en relations — evita hidratar filas completas
        // cuando solo necesitamos 1-2 campos del modelo relacionado.
        // active() excluye cancelados — el export operacional muestra solo
        // movimientos financieros vigentes. Los cancelados se ven desde el
        // tab "Cancelados" del listado de Filament (auditoría).
        $query = Deposit::query()
            ->active()
            ->with([
                'manifest:id,number,status',
                'createdBy:id,name',
            ]);

        if ($this->dateFrom) {
            $query->whereDate('deposit_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('deposit_date', '<=', $this->dateTo);
        }

        // Multi-tenant: usuarios de bodega solo ven depósitos de manifiestos
        // de su bodega. Via whereHas porque Deposit no tiene warehouse_id.
        if ($this->warehouseId) {
            $query->whereHas('manifest', function (Builder $q): void {
                $q->where('warehouse_id', $this->warehouseId);
            });
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
            match ($deposit->manifest?->status) {
                'pending' => 'Pendiente',
                'processing' => 'Procesando',
                'imported' => 'Importado',
                'closed' => 'Cerrado',
                default => $deposit->manifest?->status ?? '—',
            },
            $deposit->deposit_date ? \Carbon\Carbon::parse($deposit->deposit_date)->format('d/m/Y') : '—',
            number_format($deposit->amount, 2),
            $deposit->bank ?? '—',
            $deposit->reference ?? '—',
            // Campo real en BD es `observations` (no `notes` como decía
            // el código anterior, que siempre devolvía '—' por accesor
            // inexistente — bug silencioso corregido).
            $deposit->observations ?? '—',
            $deposit->createdBy?->name ?? '—',
            $deposit->created_at ? \Carbon\Carbon::parse($deposit->created_at)->format('d/m/Y H:i') : '—',
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
        return 'Depósitos';
    }
}
