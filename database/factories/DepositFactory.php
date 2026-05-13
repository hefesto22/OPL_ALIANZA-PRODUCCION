<?php

namespace Database\Factories;

use App\Models\Deposit;
use App\Models\Manifest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deposit>
 *
 * Default: depósito chico (HNL 100–1,000), banco BAC, fecha hoy, sin imagen
 * de comprobante. Los tests que necesiten un monto específico, banco
 * distinto, o un comprobante asociado, deben pasar el override en make().
 *
 * Nota: Deposit NO tiene warehouse_id propio — la pertenencia a bodega se
 * deriva siempre vía la relación manifest.warehouse_id. Cuando un test
 * necesita un depósito de bodega X, debe pasar un Manifest de esa bodega
 * vía ->for(...) o un manifest_id explícito.
 */
class DepositFactory extends Factory
{
    protected $model = Deposit::class;

    public function definition(): array
    {
        return [
            'manifest_id' => Manifest::factory(),
            'amount' => fake()->randomFloat(2, 100, 1000),
            'deposit_date' => now()->toDateString(),
            'bank' => fake()->randomElement(['BAC', 'FICOHSA', 'ATLANTIDA', 'BANPAIS']),
            'reference' => 'REF-'.fake()->unique()->numerify('######'),
            'observations' => null,
            'receipt_image' => null,
            'receipt_image_uploaded_at' => null,
        ];
    }

    public function withReceipt(): static
    {
        return $this->state(fn () => [
            'receipt_image' => 'deposits/receipts/'.fake()->uuid().'.jpg',
            'receipt_image_uploaded_at' => now(),
        ]);
    }
}
