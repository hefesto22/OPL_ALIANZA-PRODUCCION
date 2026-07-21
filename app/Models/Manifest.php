<?php

namespace App\Models;

use App\Support\BusinessDays;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Manifest extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'supplier_id', 'warehouse_id', 'number', 'date', 'status',
        'total_invoices', 'total_returns', 'total_to_deposit',
        'total_deposited', 'difference', 'invoices_count', 'returns_count', 'clients_count',
        'raw_json', 'closed_by', 'closed_at', 'created_by', 'updated_by',
        'returns_deadline_at',
    ];

    /**
     * La fecha límite de registro de devoluciones se fija automáticamente
     * al crear el manifiesto (N días hábiles desde su llegada, ver
     * config api.devoluciones_ventana_dias_habiles y App\Support\BusinessDays)
     * y se recalcula si la fecha operativa cambia. La condición hace que el
     * hook sea no-op en los saves frecuentes (recalculateTotals).
     */
    protected static function booted(): void
    {
        static::saving(function (Manifest $manifest) {
            if ($manifest->date && ($manifest->returns_deadline_at === null || $manifest->isDirty('date'))) {
                $manifest->returns_deadline_at = BusinessDays::deadline(
                    $manifest->date,
                    (int) config('api.devoluciones_ventana_dias_habiles', 5),
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'closed_at' => 'datetime',
            'returns_deadline_at' => 'datetime',
            'raw_json' => 'array',
            'total_invoices' => 'decimal:2',
            'total_returns' => 'decimal:2',
            'total_to_deposit' => 'decimal:2',
            'total_deposited' => 'decimal:2',
            'difference' => 'decimal:2',
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

    // ─── Ventana de registro de devoluciones ──────────────────
    // Regla operativa 2026-07-21: N días hábiles (lun–sáb) desde la llegada
    // del manifiesto; al cierre el paquete se publica a Jaremar y se congela.

    /**
     * Fecha límite de registro en el timezone operativo (Honduras).
     * Fallback calculado si la columna aún es null (pre-backfill).
     */
    public function returnsDeadline(): ?Carbon
    {
        $tz = config('manifests.dates.timezone', 'America/Tegucigalpa');

        if ($this->returns_deadline_at) {
            return $this->returns_deadline_at->copy()->timezone($tz);
        }

        if ($this->date) {
            return BusinessDays::deadline(
                $this->date,
                (int) config('api.devoluciones_ventana_dias_habiles', 5),
            );
        }

        return null;
    }

    /**
     * true cuando la ventana ya cerró: las devoluciones del manifiesto
     * quedaron publicadas a Jaremar y CONGELADAS (ni crear/editar/cancelar).
     */
    public function returnsWindowClosed(): bool
    {
        $deadline = $this->returnsDeadline();

        return $deadline !== null && now()->greaterThan($deadline);
    }

    /**
     * Días hábiles restantes para registrar devoluciones (incluye hoy).
     * 0 = ventana cerrada.
     */
    public function remainingReturnBusinessDays(): int
    {
        $deadline = $this->returnsDeadline();

        if ($deadline === null || $this->returnsWindowClosed()) {
            return 0;
        }

        return BusinessDays::remaining($deadline);
    }

    /**
     * Etiqueta humana del cierre para mensajes de validación y tooltips.
     */
    public function returnsDeadlineLabel(): string
    {
        $deadline = $this->returnsDeadline();

        return $deadline ? $deadline->format('d/m/Y').' a las 11:59 pm' : '—';
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
     * Resumen de facturas agrupado por status.
     *
     * @param  array<int, int>  $warehouseIds  Bodegas a las que limitar el
     *                                         resumen. Vacío = todas (global).
     */
    public function getInvoicesSummary(array $warehouseIds = []): array
    {
        $query = $this->invoices()
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(total), 0) as total');

        if ($warehouseIds !== []) {
            $query->whereIn('warehouse_id', $warehouseIds);
        }

        return $query
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->status => [
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ],
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
        // ── Optimización (B1): de 6 queries a 3 usando agregación condicional ──
        //
        // Antes: se lanzaban 6 queries independientes (sum, count, sum, sum,
        // count, count distinct) cada vez que un manifiesto recalculaba
        // totales — operación frecuente durante el importador de la API.
        //
        // Ahora: una query por tabla (invoices, returns, deposits) con todos
        // los agregados en un solo SELECT. Se usa CASE WHEN (ANSI SQL) en
        // vez del FILTER clause de PostgreSQL — más portable y con
        // rendimiento equivalente en Postgres 16.
        //
        // Invoices: 3 agregados en 1 query.
        //   - total_invoices / invoices_count: sólo filas con warehouse_id
        //   - clients_count: todas las filas del manifiesto (DISTINCT ignora NULLs)
        $invoiceStats = $this->invoices()
            ->selectRaw('
                COALESCE(SUM(CASE WHEN warehouse_id IS NOT NULL THEN total ELSE 0 END), 0) AS total_invoices,
                SUM(CASE WHEN warehouse_id IS NOT NULL THEN 1 ELSE 0 END)                  AS invoices_count,
                COUNT(DISTINCT client_id)                                                  AS clients_count
            ')
            ->first();

        // Returns: 2 agregados en 1 query (excluye canceladas).
        //   - total_returns: sólo las aprobadas
        //   - returns_count: activas (pending + approved)
        $returnStats = $this->returns()
            ->where('status', '!=', 'cancelled')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'approved' THEN total ELSE 0 END), 0) AS total_returns,
                COUNT(*)                                                              AS returns_count
            ")
            ->first();

        // Deposits: una sola SUM (tabla independiente, no se puede fusionar).
        // Filtro active() excluye depósitos cancelados — un cancelado existe
        // en BD para auditoría pero NO cuenta como dinero ingresado.
        $totalDeposited = (float) $this->deposits()->active()->sum('amount');

        // ── Asignar resultados al modelo ──────────────────────────────────
        $this->total_invoices = (float) ($invoiceStats->total_invoices ?? 0);
        $this->invoices_count = (int) ($invoiceStats->invoices_count ?? 0);
        // Clientes únicos por client_id (ID interno de Jaremar).
        // Se prefiere client_id sobre client_rtn porque Jaremar siempre lo
        // envía — el RTN puede estar vacío para clientes sin registro fiscal.
        $this->clients_count = (int) ($invoiceStats->clients_count ?? 0);
        $this->total_returns = (float) ($returnStats->total_returns ?? 0);
        $this->returns_count = (int) ($returnStats->returns_count ?? 0);
        $this->total_deposited = $totalDeposited;
        $this->total_to_deposit = $this->total_invoices - $this->total_returns;
        $this->difference = $this->total_to_deposit - $this->total_deposited;

        $this->save();

        $this->recalculateWarehouseTotals();
    }

    public function recalculateWarehouseTotals(): void
    {
        $byWarehouse = $this->invoices()
            ->whereNotNull('warehouse_id')
            ->selectRaw('
                warehouse_id,
                SUM(total) as total,
                COUNT(*) as count,
                COUNT(DISTINCT client_id) as clients_count
            ')
            ->groupBy('warehouse_id')
            ->get();

        // Pre-carga TODOS los totales de devoluciones agrupados por bodega
        // en UNA sola query. Antes se lanzaban 2 queries por cada bodega (N×2),
        // causando un problema N+1 cuando hay muchas bodegas en el manifiesto.
        $returnsByWarehouse = $this->returns()
            ->where('status', '!=', 'cancelled')
            ->selectRaw(
                'warehouse_id, '.
                "COALESCE(SUM(CASE WHEN status = 'approved' THEN total ELSE 0 END), 0) AS total_returns, ".
                'COUNT(*) AS returns_count'
            )
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        foreach ($byWarehouse as $row) {
            $returnData = $returnsByWarehouse[$row->warehouse_id] ?? null;
            $returns = $returnData ? (float) $returnData->total_returns : 0.0;
            $returnsCount = $returnData ? (int) $returnData->returns_count : 0;
            $toDeposit = (float) $row->total - $returns;

            ManifestWarehouseTotal::updateOrCreate(
                [
                    'manifest_id' => $this->id,
                    'warehouse_id' => $row->warehouse_id,
                ],
                [
                    'total_invoices' => $row->total,
                    'total_returns' => $returns,
                    'total_to_deposit' => $toDeposit,
                    'invoices_count' => $row->count,
                    'returns_count' => $returnsCount,
                    'clients_count' => (int) $row->clients_count,
                ]
            );
        }
    }
}
