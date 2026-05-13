<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_id', 'invoice_line_id', 'line_number',
        'product_id', 'product_description',
        'quantity_box', 'quantity', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity_box' => 'decimal:4',
            'quantity' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function return(): BelongsTo
    {
        return $this->belongsTo(InvoiceReturn::class, 'return_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
