<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un traslado de una factura de una bodega a otra.
 *
 * Lo escribe InvoiceWarehouseTransferService dentro de la misma transacción
 * que mueve la factura; los traslados anteriores a esta tabla se rescatan de
 * activity_log con `invoices:backfill-transfer-history`. Es historial: no se
 * edita ni se borra (por eso no lleva ActivityLog propio).
 */
class InvoiceWarehouseTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'from_warehouse_id', 'to_warehouse_id',
        'transferred_by', 'reason', 'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
