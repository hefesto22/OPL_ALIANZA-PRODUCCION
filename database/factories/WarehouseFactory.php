<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 *
 * Hozana maneja 3 bodegas reales: OAC (Copán), OAS (Santa Bárbara) y OAO
 * (Ocotepeque). La factory ofrece un helper por cada una para que los tests
 * puedan generar datos con los códigos canónicos en vez de inventar strings.
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * Secuencia global para el código por defecto.
     *
     * Antes el default usaba fake()->unique()->bothify('???') (3 letras al
     * azar), que PODÍA generar 'OAC'/'OAS'/'OAO'/'OAI' y chocar con el unique
     * constraint cuando un test creaba esas bodegas canónicas vía ->oac() etc.
     * (eran strings fijos, fuera del pool de unique() de faker). Resultado:
     * UniqueConstraintViolation intermitente bajo cierto orden/volumen de
     * tests. Un contador con prefijo 'WH' es único de por vida del proceso y
     * NUNCA coincide con un código canónico.
     */
    protected static int $codeSequence = 0;

    public function definition(): array
    {
        // Código único y NO canónico (cabe en string(10)). Los tests que
        // necesiten una bodega concreta usan ->oac(), ->oas(), ->oao().
        return [
            'code' => 'WH'.str_pad((string) (++static::$codeSequence), 4, '0', STR_PAD_LEFT),
            'name' => 'Bodega '.fake()->city(),
            'city' => fake()->city(),
            'department' => fake()->state(),
            'address' => fake()->address(),
            'phone' => fake()->numerify('2###-####'),
            'is_active' => true,
        ];
    }

    public function oac(): static
    {
        return $this->state(fn () => [
            'code' => 'OAC',
            'name' => 'Oficina Administrativa Copán',
            'city' => 'Santa Rosa de Copán',
            'department' => 'Copán',
        ]);
    }

    public function oas(): static
    {
        return $this->state(fn () => [
            'code' => 'OAS',
            'name' => 'Oficina Administrativa Santa Bárbara',
            'city' => 'Santa Bárbara',
            'department' => 'Santa Bárbara',
        ]);
    }

    public function oao(): static
    {
        return $this->state(fn () => [
            'code' => 'OAO',
            'name' => 'Oficina Administrativa Ocotepeque',
            'city' => 'Ocotepeque',
            'department' => 'Ocotepeque',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
