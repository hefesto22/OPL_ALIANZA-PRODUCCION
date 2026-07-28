<?php

namespace Database\Factories;

use App\Models\Manifest;
use App\Models\ManifestAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManifestAdjustment>
 *
 * Default: ajuste de un centavo hacia arriba (el caso típico de faltante
 * por redondeo bancario). Para el caso contrario usar ->sobrante().
 */
class ManifestAdjustmentFactory extends Factory
{
    protected $model = ManifestAdjustment::class;

    public function definition(): array
    {
        return [
            'manifest_id' => Manifest::factory(),
            'amount' => 0.01,
            'reason' => 'Diferencia de redondeo verificada contra el estado de cuenta.',
            'created_by' => null,
        ];
    }

    /**
     * Ajuste negativo: el manifiesto recibió de más y se da por bueno.
     */
    public function sobrante(): static
    {
        return $this->state(fn () => ['amount' => -0.01]);
    }
}
