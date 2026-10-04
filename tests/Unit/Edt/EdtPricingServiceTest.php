<?php

namespace Tests\Unit\Edt;

use App\Models\Edt\EdtPriceTier;
use App\Services\Edt\EdtPricingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EdtPricingService contra el Excel del cliente.
 *
 * tests/Fixtures/Edt/precios_excel_edt.csv tiene las 188 filas de la hoja
 * "Pedido EDTH" de "Calculo Precios - EDT Honduras.xlsx": los datos de
 * entrada y los precios que calculó EL EXCEL (detalle, caja, mayoristas al
 * 3% y 6%, costo con el 15% de descuento de operación). Si alguien cambia
 * una fórmula o un redondeo, este test lo agarra antes de que un precio
 * salga distinto al del Excel.
 *
 * El fixture no lleva códigos ni descripciones de productos: solo números.
 */
class EdtPricingServiceTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/Edt/precios_excel_edt.csv';

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function filasDelExcel(): array
    {
        $handle = fopen(self::FIXTURE, 'r');
        $header = fgetcsv($handle, escape: '\\');
        $cases = [];

        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            $row = array_combine($header, $line);
            $cases['fila '.$row['fila_excel']] = [$row];
        }

        fclose($handle);

        return $cases;
    }

    public function test_el_fixture_tiene_las_188_filas_del_excel(): void
    {
        $this->assertCount(188, self::filasDelExcel());
    }

    /**
     * @param  array<string, string>  $fila
     */
    #[DataProvider('filasDelExcel')]
    public function test_da_los_mismos_precios_que_el_excel(array $fila): void
    {
        $quote = (new EdtPricingService)->quote(
            listPrice: $fila['precio_lista'],
            unitsPerBox: (int) $fila['unidades_por_caja'],
            isvPct: $fila['isv_pct'],
            operationDiscountPct: '15',
            tiers: $this->tiersDelExcel(),
        );

        $this->assertSame($fila['costo_unidad'], $quote->unitCost, 'Costo por unidad (PC + ISV)');
        $this->assertSame($fila['detalle_unidad'], $quote->retailUnitPrice, 'Detalle por unidad (PVD + ISV)');
        $this->assertSame($fila['detalle_caja'], $quote->retailBoxPrice, 'Detalle por caja (Total PVD)');
        $this->assertSame($fila['mayorista_1_caja'], $quote->wholesale[0]['box_price'], 'Mayorista 1 por caja');
        $this->assertSame($fila['mayorista_2_caja'], $quote->wholesale[1]['box_price'], 'Mayorista 2 por caja');
    }

    public function test_gallo_lata_ejemplo_completo_con_margenes(): void
    {
        // CR GALLO LATA 350ML: exento, 24 por caja, lista L 17.60.
        $quote = (new EdtPricingService)->quote('17.60', 24, '0', '15', $this->tiersDelExcel());

        $this->assertSame('14.9600', $quote->unitCost);
        $this->assertSame('17.60', $quote->retailUnitPrice);
        $this->assertSame('422.40', $quote->retailBoxPrice);
        $this->assertSame('410.00', $quote->wholesale[0]['box_price']);
        $this->assertSame('398.00', $quote->wholesale[1]['box_price']);

        // Margen sobre venta: detalle = el 15% del proveedor; mayoristas menos.
        $this->assertSame('15.00', $quote->retailMarginPct);
        $this->assertSame('12.43', $quote->wholesale[0]['margin_pct']);
        $this->assertSame('9.79', $quote->wholesale[1]['margin_pct']);
    }

    public function test_la_escala_se_elige_por_cajas_del_mismo_codigo(): void
    {
        $quote = (new EdtPricingService)->quote('17.60', 24, '0', '15', $this->tiersDelExcel());

        $this->assertNull($quote->wholesaleForBoxes(24), '24 cajas todavía es precio de detalle');
        $this->assertSame('MAYORISTA 1', $quote->wholesaleForBoxes(25)['name']);
        $this->assertSame('MAYORISTA 1', $quote->wholesaleForBoxes(49)['name']);
        $this->assertSame('MAYORISTA 2', $quote->wholesaleForBoxes(50)['name']);
        $this->assertSame('MAYORISTA 2', $quote->wholesaleForBoxes(500)['name']);
    }

    public function test_las_escalas_salen_ordenadas_aunque_lleguen_desordenadas(): void
    {
        $quote = (new EdtPricingService)->quote('17.60', 24, '0', '15', array_reverse($this->tiersDelExcel()));

        $this->assertSame([25, 50], array_column($quote->wholesale, 'min_boxes'));
    }

    public function test_el_mayorista_redondea_hacia_arriba_al_lempira(): void
    {
        // 10.00 × 1 × 0.97 = 9.70 → 10 (no 9.70 ni 9).
        $tier = new EdtPriceTier(['name' => 'X', 'min_boxes' => 1, 'discount_pct' => '3']);
        $quote = (new EdtPricingService)->quote('10.00', 1, '0', '15', [$tier]);

        $this->assertSame('10.00', $quote->wholesale[0]['box_price']);
    }

    public function test_el_detalle_redondea_a_centavos_con_mitad_hacia_arriba(): void
    {
        // 0.0435 × 1.15 = 0.050025 → 0.05; 10.0043 × 1.15 = 11.504945 → 11.50.
        $this->assertSame('0.05', (new EdtPricingService)->quote('0.0435', 1, '15', '15', [])->retailUnitPrice);
        $this->assertSame('11.50', (new EdtPricingService)->quote('10.0043', 1, '15', '15', [])->retailUnitPrice);
        // Exactamente a la mitad: 1.10 × 1.15 = 1.265 → 1.27 (como ROUND de Excel, no 1.26).
        $this->assertSame('1.27', (new EdtPricingService)->quote('1.10', 1, '15', '15', [])->retailUnitPrice);
    }

    public function test_isv_18_por_ciento(): void
    {
        $quote = (new EdtPricingService)->quote('100', 12, '18', '15', []);

        $this->assertSame('118.00', $quote->retailUnitPrice);
        $this->assertSame('1416.00', $quote->retailBoxPrice);
        $this->assertSame('100.3000', $quote->unitCost);
    }

    public function test_sin_escalas_no_hay_precios_mayoristas(): void
    {
        $quote = (new EdtPricingService)->quote('17.60', 24, '0', '15', []);

        $this->assertSame([], $quote->wholesale);
        $this->assertNull($quote->wholesaleForBoxes(100));
    }

    /**
     * Las dos escalas que calcula hoy el Excel (celdas R1 y U1).
     *
     * @return list<EdtPriceTier>
     */
    private function tiersDelExcel(): array
    {
        $uno = (new EdtPriceTier)->forceFill(['id' => 1, 'name' => 'MAYORISTA 1', 'min_boxes' => 25, 'discount_pct' => '3.00']);
        $dos = (new EdtPriceTier)->forceFill(['id' => 2, 'name' => 'MAYORISTA 2', 'min_boxes' => 50, 'discount_pct' => '6.00']);

        return [$uno, $dos];
    }
}
