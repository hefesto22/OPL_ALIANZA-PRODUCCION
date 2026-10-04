<?php

namespace Tests\Unit\Casts;

use App\Casts\Uppercase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cast Uppercase: regla del EDT de guardar todo el texto en mayúsculas.
 */
class UppercaseTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function casos(): array
    {
        return [
            'minúsculas' => ['central america', 'CENTRAL AMERICA'],
            'tildes y eñes' => ['Piña de Copán', 'PIÑA DE COPÁN'],
            'espacios en los extremos' => ['  cbc  ', 'CBC'],
            'espacios repetidos adentro' => ['Del    Monte', 'DEL MONTE'],
            'tabs y saltos de línea' => ["Gran\tDía\nHonduras", 'GRAN DÍA HONDURAS'],
            'ya en mayúsculas' => ['GRANOLA 380G', 'GRANOLA 380G'],
            'vacío queda null' => ['   ', null],
            'null queda null' => [null, null],
        ];
    }

    #[DataProvider('casos')]
    public function test_normaliza_a_mayusculas(?string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, Uppercase::normalize($entrada));

        $model = new class extends Model {};
        $this->assertSame($esperado, (new Uppercase)->set($model, 'name', $entrada, []));
    }
}
