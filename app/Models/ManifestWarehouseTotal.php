<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManifestWarehouseTotal extends Model
{
    use HasFactory;

    protected $fillable = [
        'manifest_id', 'warehouse_id',
        'total_invoices', 'total_returns',
        'total_to_deposit', 'total_deposited',
        'difference', 'invoices_count', 'returns_count', 'clients_count',
    ];

    protected function casts(): array
    {
        return [
            'total_invoices' => 'decimal:2',
            'total_returns' => 'decimal:2',
            'total_to_deposit' => 'decimal:2',
            'total_deposited' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
