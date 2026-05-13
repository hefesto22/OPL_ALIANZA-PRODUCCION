<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'manifest_id', 'warehouse_id', 'status', 'jaremar_id', 'invoice_number',
        'lx_number', 'order_number', 'invoice_date', 'due_date', 'print_limit_date',
        'seller_id', 'seller_name', 'client_id', 'client_name', 'client_rtn',
        'deliver_to', 'department', 'municipality', 'neighborhood', 'address',
        'phone', 'longitude', 'latitude', 'route_number', 'cai',
        'range_start', 'range_end', 'payment_type', 'credit_days',
        'invoice_type', 'invoice_status', 'matriz_address', 'branch_address',
        'importe_excento', 'importe_exento_desc', 'importe_exento_isv18',
        'importe_exento_isv15', 'importe_exento_total', 'importe_exonerado',
        'importe_exonerado_desc', 'importe_exonerado_isv18', 'importe_exonerado_isv15',
        'importe_exonerado_total', 'importe_gravado', 'importe_gravado_desc',
        'importe_gravado_isv18', 'importe_gravado_isv15', 'importe_gravado_total',
        'discounts', 'isv18', 'isv15', 'total', 'total_returns', 'is_printed', 'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'print_limit_date' => 'date',
            'printed_at' => 'datetime',
            'is_printed' => 'boolean',

            // Coordenadas (precisión geográfica, no fiscal)
            'longitude' => 'decimal:7',
            'latitude' => 'decimal:7',

            // ── Importes fiscales Honduras ──────────────────────────────
            // Todos en HNL con 2 decimales por Decreto 51-2003 y normativa SAR.
            // Usar `decimal:2` garantiza precisión al leer del modelo y evita
            // la pérdida silenciosa que ocurriría con float al hacer aritmética
            // sobre valores como 0.1 + 0.2 ≠ 0.3.
            //
            // El cast retorna string formateado "123.45". Los callsites de
            // aritmética en este proyecto (ReturnsDetailExport, PrintReports,
            // ApiInvoiceImporterService) ya manejan esto vía cast explícito
            // a float, helpers, o getRawOriginal() para evitar el cast.
            'total' => 'decimal:2',
            'total_returns' => 'decimal:2',
            'discounts' => 'decimal:2',
            'isv15' => 'decimal:2',
            'isv18' => 'decimal:2',
            'importe_excento' => 'decimal:2',
            'importe_exento_desc' => 'decimal:2',
            'importe_exento_isv18' => 'decimal:2',
            'importe_exento_isv15' => 'decimal:2',
            'importe_exento_total' => 'decimal:2',
            'importe_exonerado' => 'decimal:2',
            'importe_exonerado_desc' => 'decimal:2',
            'importe_exonerado_isv18' => 'decimal:2',
            'importe_exonerado_isv15' => 'decimal:2',
            'importe_exonerado_total' => 'decimal:2',
            'importe_gravado' => 'decimal:2',
            'importe_gravado_desc' => 'decimal:2',
            'importe_gravado_isv18' => 'decimal:2',
            'importe_gravado_isv15' => 'decimal:2',
            'importe_gravado_total' => 'decimal:2',
        ];
    }

    /**
     * Auditoría de Invoice — registra cambios en los campos críticos del
     * ciclo de vida y asignación. NO loguea los importes fiscales porque:
     *   1. Una factura importada NO cambia sus importes (vienen de Jaremar).
     *   2. Re-importaciones masivas generarían ruido en activity_log.
     *   3. Los cambios de importe son raros y, si ocurren, los importadores
     *      ya los registran vía `api_invoice_imports` y conflict tracking.
     *
     * Campos auditados:
     *   - status: ciclo de vida (imported → pending_warehouse → assigned →
     *             partial_return → returned → rejected). Cambio regulatorio.
     *   - warehouse_id: asignación a bodega. Decisión operativa.
     *   - manifest_id: reasignación entre manifiestos. Rara pero existe.
     *   - is_printed: control operativo de impresión.
     *   - total_returns: indicador derivado pero útil para detectar
     *                    recálculos anómalos sin contexto.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'warehouse_id', 'manifest_id', 'is_printed', 'total_returns'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Factura {$eventName}");
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeImported($query)
    {
        return $query->where('status', 'imported');
    }

    public function scopePendingWarehouse($query)
    {
        return $query->where('status', 'pending_warehouse');
    }

    public function scopeReturned($query)
    {
        return $query->where('status', 'returned');
    }

    public function scopeByRoute($query, string $routeNumber)
    {
        return $query->where('route_number', trim($routeNumber));
    }

    public function scopeByWarehouse($query, string $code)
    {
        return $query->whereHas('warehouse', fn ($q) => $q->where('code', $code));
    }

    public function scopeNotPrinted($query)
    {
        return $query->where('is_printed', false);
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(InvoiceReturn::class);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Saldo neto = total factura - total devuelto (aprobado + pendiente).
     *
     * `total_returns` es una columna pre-calculada que se actualiza
     * en ReturnService cada vez que una devolución cambia de estado.
     * Antes era un accessor con query N+1; ahora es O(1).
     */
    public function getNetTotalAttribute(): float
    {
        return (float) $this->total - (float) $this->total_returns;
    }
}
