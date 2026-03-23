<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Manifest extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'supplier_id', 'warehouse_id', 'number', 'date', 'status',
        'total_invoices', 'total_returns', 'total_to_deposit',
        'total_deposited', 'difference', 'invoices_count', 'returns_count', 'clients_count',
        'raw_json', 'closed_by', 'closed_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date'             => 'date',
            'closed_at'        => 'datetime',
            'raw_json'         => 'array',
            'total_invoices'   => 'decimal:2',
            'total_returns'    => 'decimal:2',
            'total_to_deposit' => 'decimal:2',
            'total_deposited'  => 'decimal:2',
            'difference'       => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'total_invoices', 'total_returns', 'total_deposited'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeImported($query)
    {
        return $query->where('status', 'imported');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseTotals(): HasMany
    {
        return $this->hasMany(ManifestWarehouseTotal::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(InvoiceReturn::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Listo para cerrar cuando:
     * - No está ya cerrado.
     * - La diferencia entre depósitos y total a depositar es 0.
     * - Tiene al menos algo que depositar (no es manifiesto vacío).
     * - No tiene devoluciones pendientes de revisión.
     *   Cerrar con devoluciones pendientes las dejaría en un limbo:
     *   no se podrían aprobar/rechazar sin reabrir el manifiesto.
     */
    public function isReadyToClose(): bool
    {
        if ($this->isClosed()) {
            return false;
        }

        if ((float) $this->difference != 0) {
            return false;
        }

        if ((float) $this->total_to_deposit <= 0) {
            return false;
        }

        if ($this->returns()->where('status', 'pending')->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Cierra el manifiesto. Registra quién y cuándo.
     */
    public function close(int $userId): void
    {
        $this->update([
            'status'    => 'closed',
            'closed_by' => $userId,
            'closed_at' => now(),
        ]);
    }

    /**
     * Reabre un manifiesto cerrado. Solo super_admin.
     */
    public function reopen(): void
    {
        $this->update([
            'status'    => 'imported',
            'closed_by' => null,
            'closed_at' => null,
        ]);
    }

    /**
     * Resumen de facturas agrupado por status.
     */
    public function getInvoicesSummary(?int $warehouseId = null): array
    {
        $query = $this->invoices()
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(total), 0) as total');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->status => [
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ]
            ])
            ->toArray();
    }

    public function getTotalSentCount(): int
    {
        return $this->invoices()->count();
    }

    public function getTotalSentAmount(): float
    {
        return (float) $this->invoices()->sum('total');
    }

    public function recalculateTotals(): void
    {
        $this->total_invoices   = $this->invoices()->whereNotNull('warehouse_id')->sum('total');
        $this->total_returns    = $this->returns()->where('status', 'approved')->sum('total');
        $this->total_to_deposit = $this->total_invoices - $this->total_returns;
        $this->total_deposited  = $this->deposits()->sum('amount');
        $this->difference       = $this->total_to_deposit - $this->total_deposited;
        $this->invoices_count   = $this->invoices()->whereNotNull('warehouse_id')->count();
        $this->returns_count    = $this->returns()->count();

        // Clientes únicos por client_id (ID interno de Jaremar).
        // Se prefiere client_id sobre client_rtn porque Jaremar siempre lo
        // envía — el RTN puede estar vacío para clientes sin registro fiscal.
        $this->clients_count    = $this->invoices()
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');

        $this->save();

        $this->recalculateWarehouseTotals();
    }

    public function recalculateWarehouseTotals(): void
    {
        $byWarehouse = $this->invoices()
            ->whereNotNull('warehouse_id')
            ->selectRaw("
                warehouse_id,
                SUM(total) as total,
                COUNT(*) as count,
                COUNT(DISTINCT client_id) as clients_count
            ")
            ->groupBy('warehouse_id')
            ->get();

        // Pre-carga TODOS los totales de devoluciones agrupados por bodega
        // en UNA sola query. Antes se lanzaban 2 queries por cada bodega (N×2),
        // causando un problema N+1 cuando hay muchas bodegas en el manifiesto.
        $returnsByWarehouse = $this->returns()
            ->selectRaw(
                'warehouse_id, ' .
                "COALESCE(SUM(CASE WHEN status = 'approved' THEN total ELSE 0 END), 0) AS total_returns, " .
                'COUNT(*) AS returns_count'
            )
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        foreach ($byWarehouse as $row) {
            $returnData   = $returnsByWarehouse[$row->warehouse_id] ?? null;
            $returns      = $returnData ? (float) $returnData->total_returns : 0.0;
            $returnsCount = $returnData ? (int) $returnData->returns_count   : 0;
            $toDeposit    = (float) $row->total - $returns;

            ManifestWarehouseTotal::updateOrCreate(
                [
                    'manifest_id'  => $this->id,
                    'warehouse_id' => $row->warehouse_id,
                ],
                [
                    'total_invoices'   => $row->total,
                    'total_returns'    => $returns,
                    'total_to_deposit' => $toDeposit,
                    'invoices_count'   => $row->count,
                    'returns_count'    => $returnsCount,
                    'clients_count'    => (int) $row->clients_count,
                ]
            );
        }
    }
}