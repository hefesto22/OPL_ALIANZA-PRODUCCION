<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceWarehouseTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceWarehouseTransfer>
 */
class InvoiceWarehouseTransferFactory extends Factory
{
    protected $model = InvoiceWarehouseTransfer::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'from_warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => Warehouse::factory(),
            'transferred_by' => User::factory(),
            'reason' => 'Jaremar la mandó con el almacén equivocado.',
            'transferred_at' => now(),
        ];
    }
}
