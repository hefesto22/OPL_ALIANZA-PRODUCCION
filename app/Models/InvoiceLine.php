<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'jaremar_line_id', 'invoice_jaremar_id', 'line_number',
        'product_id', 'product_description', 'product_type', 'unit_sale',
        'quantity_fractions', 'quantity_decimal', 'quantity_box',
        'quantity_min_sale', 'conversion_factor',
        'cost', 'price', 'price_min_sale',
        'subtotal', 'discount', 'discount_percent',
        'tax', 'tax_percent', 'tax18', 'total',
        'weight', 'volume',
    ];

    protected function casts(): array
    {
        return [
            'total'    => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax'      => 'decimal:2',
            'discount' => 'decimal:2',
        ];
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function returnLines(): HasMany
    {
        return $this->hasMany(ReturnLine::class);
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function getReturnedQuantityAttribute(): float
    {
        return $this->returnLines()->sum('quantity');
    }

    public function getRemainingQuantityAttribute(): float
    {
        return $this->quantity_fractions - $this->returned_quantity;
    }
}