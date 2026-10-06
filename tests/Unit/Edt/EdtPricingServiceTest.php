<?php

namespace Tests\Unit\Edt;

use App\Models\Edt\EdtPriceTier;
use App\Services\Edt\EdtPriceQuote;
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
        $quote = $this->excelQuote($fila['precio_lista'], (int) $fila['unidades_por_caja'], $fila['isv_pct'], $this->tiersDelExcel());

        $this->assertSame($fila['costo_unidad'], $quote->unitCost, 'Costo por unidad (PC + ISV)');
        $this->assertSame($fila['detalle_unidad'], $quote->retailUnitPrice, 'Detalle por unidad (PVD + ISV)');
        $this->assertSame($fila['detalle_caja'], $quote->retailBoxPrice, 'Detalle por caja (Total PVD)');
        $this->assertSame($fila['mayorista_1_caja'], $quote->wholesale[0]['box_price'], 'Mayorista 1 por caja');
        $this->assertSame($fila['mayorista_2_caja'], $quote->wholesale[1]['box_price'], 'Mayorista 2 por caja');
    }

    public function test_gallo_lata_ejemplo_completo_con_margenes(): void
    {
        // CR GALLO LATA 350ML: exento, 24 por caja, lista L 17.60.
        $quote = $this->excelQuote('17.60', 24, '0', $this->tiersDelExcel());

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
        $quote = $this->excelQuote('17.60', 24, '0', $this->tiersDelExcel());

        $this->assertNull($quote->wholesaleForBoxes(24), '24 cajas todavía es precio de detalle');
        $this->assertSame('MAYORISTA 1', $quote->wholesaleForBoxes(25)['name']);
        $this->assertSame('MAYORISTA 1', $quote->wholesaleForBoxes(49)['name']);
        $this->assertSame('MAYORISTA 2', $quote->wholesaleForBoxes(50)['name']);
        $this->assertSame('MAYORISTA 2', $quote->wholesaleForBoxes(500)['name']);
    }

    public function test_las_escalas_salen_ordenadas_aunque_lleguen_desordenadas(): void
    {
        $quote = $this->excelQuote('17.60', 24, '0', array_reverse($this->tiersDelExcel()));

        $this->assertSame([25, 50], array_column($quote->wholesale, 'min_boxes'));
    }

    public function test_el_mayorista_redondea_hacia_arriba_al_lempira(): void
    {
        // 10.00 × 1 × 0.97 = 9.70 → 10 (no 9.70 ni 9).
        $tier = new EdtPriceTier(['name' => 'X', 'min_boxes' => 1, 'discount_pct' => '3']);
        $quote = $this->excelQuote('10.00', 1, '0', [$tier]);

        $this->assertSame('10.00', $quote->wholesale[0]['box_price']);
    }

    public function test_el_detalle_redondea_a_centavos_con_mitad_hacia_arriba(): void
    {
        // 0.0435 × 1.15 = 0.050025 → 0.05; 10.0043 × 1.15 = 11.504945 → 11.50.
        $this->assertSame('0.05', $this->excelQuote('0.0435', 1, '15', [])->retailUnitPrice);
        $this->assertSame('11.50', $this->excelQuote('10.0043', 1, '15', [])->retailUnitPrice);
        // Exactamente a la mitad: 1.10 × 1.15 = 1.265 → 1.27 (como ROUND de Excel, no 1.26).
        $this->assertSame('1.27', $this->excelQuote('1.10', 1, '15', [])->retailUnitPrice);
    }

    public function test_isv_18_por_ciento(): void
    {
        $quote = $this->excelQuote('100', 12, '18', []);

        $this->assertSame('118.00', $quote->retailUnitPrice);
        $this->assertSame('1416.00', $quote->retailBoxPrice);
        $this->assertSame('100.3000', $quote->unitCost);
    }

    public function test_lista_desde_el_precio_con_isv(): void
    {
        $pricing = new EdtPricingService;

        $this->assertSame('26.0870', $pricing->listPriceFromRetail('30', '15'));
        $this->assertSame('25.4237', $pricing->listPriceFromRetail('30', '18'));
        $this->assertSame('30.0000', $pricing->listPriceFromRetail('30', '0'));
    }

    public function test_el_precio_con_isv_escrito_vuelve_exacto_como_detalle(): void
    {
        // Para cualquier precio en centavos, la lista calculada da de vuelta
        // exactamente ese detalle por unidad (0.01 a 300.00, con 0, 15 y 18%).
        $pricing = new EdtPricingService;

        foreach (['0', '15', '18'] as $isv) {
            for ($cents = 1; $cents <= 30000; $cents++) {
                $price = number_format($cents / 100, 2, '.', '');
                $list = $pricing->listPriceFromRetail($price, $isv);

                $this->assertSame(
                    $price,
                    $pricing->retailUnitPrice($list, $isv),
                    "ISV {$isv}%: {$price} → lista {$list}",
                );
            }
        }
    }

    public function test_si_el_precio_con_isv_no_cambia_se_conserva_la_lista_vigente(): void
    {
        $pricing = new EdtPricingService;

        // 17.86 × 1.15 = 20.539 → 20.54, pero 20.54 ÷ 1.15 = 17.8609.
        $this->assertSame('17.8609', $pricing->listPriceFromRetail('20.54', '15'));
        $this->assertSame('17.8600', $pricing->listPriceFromRetail('20.54', '15', currentListPrice: '17.86'));
        // Si el precio sí cambió, la lista vigente no importa.
        $this->assertSame('26.0870', $pricing->listPriceFromRetail('30.00', '15', currentListPrice: '17.86'));
        // Con otro ISV, la lista vigente solo se conserva si da el mismo precio.
        $this->assertSame('17.8600', $pricing->listPriceFromRetail('21.07', '18', currentListPrice: '17.86'));
    }

    public function test_detalle_por_unidad_desde_la_lista(): void
    {
        $pricing = new EdtPricingService;

        $this->assertSame('30.00', $pricing->retailUnitPrice('26.0870', '15'));
        $this->assertSame('1.27', $pricing->retailUnitPrice('1.10', '15'));
        $this->assertSame('17.60', $pricing->retailUnitPrice('17.60', '0'));
    }

    public function test_el_costo_por_caja_sugerido_es_el_del_excel(): void
    {
        $pricing = new EdtPricingService;

        // Fila 3: 29.20 − 15% + 15% ISV = 28.543 por unidad × 50.
        $this->assertSame('1427.1500', $pricing->boxCostFromList('29.20', 50, '15', '15'));
        // Exento: 17.60 − 15% = 14.96 × 24.
        $this->assertSame('359.0400', $pricing->boxCostFromList('17.60', 24, '0', '15'));
    }

    public function test_el_costo_es_el_del_producto_y_no_sale_de_la_lista(): void
    {
        // Mismo precio que el Gallo (L 17.60, 24 por caja, exento) pero con
        // costo por contrato de L 336.00 la caja (L 14.00 c/u) en lugar del 15%.
        $quote = (new EdtPricingService)->quote('17.60', 24, '0', '336', $this->tiersDelExcel());

        $this->assertSame('14.0000', $quote->unitCost);
        $this->assertSame('336.0000', $quote->boxCost);
        $this->assertSame('422.40', $quote->retailBoxPrice);
        // Margen = (422.40 − 336) ÷ 422.40; el precio de venta no cambia.
        $this->assertSame('20.45', $quote->retailMarginPct);
        $this->assertSame('410.00', $quote->wholesale[0]['box_price']);
        $this->assertSame('18.05', $quote->wholesale[0]['margin_pct']);
    }

    public function test_quitar_y_poner_el_isv(): void
    {
        $pricing = new EdtPricingService;

        $this->assertSame('1241.0000', $pricing->withoutIsv('1427.15', '15'));
        $this->assertSame('1427.1500', $pricing->withIsv('1241', '15'));
        $this->assertSame('359.0400', $pricing->withIsv('359.04', '0'));
    }

    public function test_al_cambiar_isv_o_unidades_el_costo_por_unidad_sin_isv_se_mantiene(): void
    {
        $pricing = new EdtPricingService;

        // Gallo: 359.04 la caja de 24 sin ISV → con 15%: 359.04 × 1.15.
        $this->assertSame('412.8960', $pricing->rescaleBoxCost('359.04', 24, '0', 24, '15'));
        // Caja de 24 → 12: la mitad.
        $this->assertSame('179.5200', $pricing->rescaleBoxCost('359.04', 24, '0', 12, '0'));
        // 1427.15 con 15% (1241 sin ISV, 12 u.) → 6 u. con 18%: 620.50 × 1.18.
        $this->assertSame('732.1900', $pricing->rescaleBoxCost('1427.15', 12, '15', 6, '18'));
        $this->assertSame('1241.0000', $pricing->rescaleBoxCost('1427.15', 12, '15', 12, '0'));
    }

    public function test_sin_escalas_no_hay_precios_mayoristas(): void
    {
        $quote = $this->excelQuote('17.60', 24, '0', []);

        $this->assertSame([], $quote->wholesale);
        $this->assertNull($quote->wholesaleForBoxes(100));
    }

    /**
     * Cotiza como el Excel: el costo por caja sale de la lista − 15% + ISV.
     *
     * @param  iterable<EdtPriceTier>  $tiers
     */
    private function excelQuote(string $list, int $units, string $isv, iterable $tiers): EdtPriceQuote
    {
        $pricing = new EdtPricingService;

        return $pricing->quote($list, $units, $isv, $pricing->boxCostFromList($list, $units, $isv, '15'), $tiers);
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
