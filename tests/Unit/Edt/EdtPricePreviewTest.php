<?php

namespace Tests\Unit\Edt;

use App\Filament\Resources\Edt\Products\Support\EdtPricePreview;
use App\Filament\Resources\Edt\Products\Support\PriceSnapshot;
use App\Models\Edt\EdtPriceTier;
use App\Services\Edt\EdtPricingService;
use Tests\TestCase;

/**
 * Datos de la vista previa de precios: qué cambió, hacia dónde y de qué
 * color (verde si le conviene al EDT, rojo si no).
 */
class EdtPricePreviewTest extends TestCase
{
    public function test_sin_datos_validos_muestra_el_estado_vacio(): void
    {
        $this->assertSame(['empty' => true], EdtPricePreview::data(null));
    }

    public function test_subir_el_precio_es_verde_y_el_costo_que_sube_es_rojo(): void
    {
        $data = EdtPricePreview::data($this->snapshot('26.0870'), before: $this->snapshot('20.00'));

        $this->assertTrue($data['compare']);
        $this->assertSame('L 23.00', $data['headline']['old']);
        $this->assertSame('L 30.00', $data['headline']['new']);
        $this->assertSame('+L 7.00 · +30.43%', $data['headline']['delta']);
        $this->assertSame(['up', 'good'], [$data['headline']['trend'], $data['headline']['tone']]);
        $this->assertSame('L 20.00', $data['previousListPrice']);

        $rows = $this->rowsByLabel($data);
        $this->assertSame(['up', 'good'], [$rows['Por caja']['trend'], $rows['Por caja']['tone']]);
        $this->assertSame(['up', 'bad'], [$rows['Costo por unidad']['trend'], $rows['Costo por unidad']['tone']]);
        $this->assertSame('Desde 25 cajas · 3% de descuento', $rows['MAYORISTA 1']['sub']);
        // El margen al detalle no se mueve: siempre es el descuento del proveedor.
        $this->assertSame('same', $rows['Margen al detalle']['trend']);
        $this->assertNull($rows['Margen al detalle']['delta']);
    }

    public function test_bajar_el_precio_es_rojo_y_el_costo_que_baja_es_verde(): void
    {
        $data = EdtPricePreview::data($this->snapshot('20.00'), before: $this->snapshot('26.0870'));
        $rows = $this->rowsByLabel($data);

        $this->assertSame("\u{2212}L 7.00 · \u{2212}23.33%", $data['headline']['delta']);
        $this->assertSame(['down', 'bad'], [$rows['Por unidad']['trend'], $rows['Por unidad']['tone']]);
        $this->assertSame(['down', 'good'], [$rows['Costo por unidad']['trend'], $rows['Costo por unidad']['tone']]);
    }

    public function test_sin_cambios_no_hay_diferencias(): void
    {
        $data = EdtPricePreview::data($this->snapshot('20.00'), before: $this->snapshot('20.00'));

        $this->assertNull($data['headline']['delta']);
        $this->assertNull($data['previousListPrice']);
        $this->assertSame([], $data['changes']);
        foreach ($this->rowsByLabel($data) as $label => $row) {
            $this->assertSame('same', $row['trend'], $label);
        }
    }

    public function test_avisa_si_cambian_el_isv_o_las_unidades_por_caja(): void
    {
        $data = EdtPricePreview::data(
            $this->snapshot('20.00', unitsPerBox: 24, isvPct: '18'),
            before: $this->snapshot('20.00', unitsPerBox: 12, isvPct: '15'),
        );

        $this->assertSame(['ISV 15% → 18%', 'Unidades por caja 12 → 24'], $data['changes']);
        $this->assertSame('24 unidades', $this->rowsByLabel($data)['Por caja']['sub']);
    }

    public function test_el_margen_mayorista_se_compara_en_puntos(): void
    {
        $rows = $this->rowsByLabel(EdtPricePreview::data($this->snapshot('26.0870'), before: $this->snapshot('20.00')));

        // 12.46% → 12.57% con caja de 12 (CEIL al lempira mueve el margen).
        $this->assertSame('12.46%', $rows['Margen MAYORISTA 1']['old']);
        $this->assertSame('+0.11 pts', $rows['Margen MAYORISTA 1']['delta']);
    }

    public function test_sin_comparar_no_hay_columna_actual(): void
    {
        $data = EdtPricePreview::data($this->snapshot('26.0870'));

        $this->assertFalse($data['compare']);
        $this->assertNull($data['headline']['old']);
        $this->assertNull($data['previousListPrice']);
        $this->assertNull($this->rowsByLabel($data)['Por unidad']['old']);
    }

    public function test_avisa_si_el_precio_queda_por_debajo_del_costo(): void
    {
        $this->assertFalse(EdtPricePreview::data($this->snapshot('20.00'))['belowCost']);

        $pricing = new EdtPricingService;
        $caro = new PriceSnapshot(
            quote: $pricing->quote('20.00', 12, '15', '300', []),
            listPrice: '20.00',
            boxCost: '300',
            unitsPerBox: 12,
            isvPct: '15',
        );

        // Caja al detalle: 23.00 × 12 = 276.00, por debajo de 300.
        $this->assertTrue(EdtPricePreview::data($caro)['belowCost']);
        $this->assertStringContainsString('por debajo de lo que cuesta', (string) EdtPricePreview::render($caro));
    }

    private function snapshot(string $listPrice, int $unitsPerBox = 12, string $isvPct = '15'): PriceSnapshot
    {
        $tiers = [
            (new EdtPriceTier)->forceFill(['id' => 1, 'name' => 'MAYORISTA 1', 'min_boxes' => 25, 'discount_pct' => '3.00']),
            (new EdtPriceTier)->forceFill(['id' => 2, 'name' => 'MAYORISTA 2', 'min_boxes' => 50, 'discount_pct' => '6.00']),
        ];

        $pricing = new EdtPricingService;
        $boxCost = $pricing->boxCostFromList($listPrice, $unitsPerBox, $isvPct, '15');

        return new PriceSnapshot(
            quote: $pricing->quote($listPrice, $unitsPerBox, $isvPct, $boxCost, $tiers),
            listPrice: $listPrice,
            boxCost: $boxCost,
            unitsPerBox: $unitsPerBox,
            isvPct: $isvPct,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function rowsByLabel(array $data): array
    {
        return collect($data['groups'])->flatMap(fn (array $group): array => $group['rows'])->keyBy('label')->all();
    }
}
