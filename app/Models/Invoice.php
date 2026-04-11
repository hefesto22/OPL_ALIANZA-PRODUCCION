<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

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
        'discounts', 'isv18', 'isv15', 'total', 'is_printed', 'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date'     => 'date',
            'due_date'         => 'date',
            'print_limit_date' => 'date',
            'printed_at'       => 'datetime',
            'is_printed'       => 'boolean',
            'total'            => 'decimal:2',
            'longitude'        => 'decimal:7',
            'latitude'         => 'decimal:7',
        ];
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
        return $query->whereHas('warehouse', fn($q) => $q->where('code', $code));
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

    public function getTotalReturnsAttribute(): float
    {
        return $this->returns()->sum('total');
    }

    public function getNetTotalAttribute(): float
    {
        return $this->total - $this->total_returns;
    }
}