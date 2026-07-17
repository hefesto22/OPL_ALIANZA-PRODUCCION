<?php

namespace Tests\Feature\PrintReports;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Reporte Sin ISV — caso BONIFICACIÓN: total de la factura = solo el ISV.
 *
 * Contexto (2026-07-17): 11 facturas en prod llegaron con isv15 = total y
 * a primera vista parecía un dato imposible de Jaremar ("el ISV nunca
 * puede igualar el total"). FALSO — es una BONIFICACIÓN legítima: las
 * líneas van con valor 0 (mercadería bonificada) pero el ISV de esa
 * mercadería SÍ se cobra. Ejemplo real (manifiesto 789920, factura
 * 002-001-01-03891192): SubTotal L 1,112.30 → ISV 15% L 166.85 →
 * TOTAL L 166.85. La base gravada va POR FUERA del total de la factura.
 *
 * Por lo tanto el neto sin ISV de esas facturas ES L 0.00 — todo lo
 * cobrado es impuesto — y el cálculo original del reporte es correcto.
 *
 * Este test protege ese comportamiento: NO "sanear" ni excluir el ISV
 * cuando iguala el total. Ya se intentó una vez tratándolo como factura
 * exenta y hubo que revertirlo el mismo día.
 */
class ManifestsSinIsvAnomalousIsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_bonificacion_con_total_igual_al_isv_resta_completa_y_neto_cero(): void
    {
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);
        Supplier::factory()->create(['is_active' => true]);

        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        // Factura normal: total 230.00 con isv15 30.00 → neto 200.00.
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => 'F-NORMAL',
            'total' => 230.00,
            'isv15' => 30.00,
            'isv18' => 0,
        ]);

        // BONIFICACIÓN (patrón real de Jaremar): el cliente paga SOLO el
        // ISV de la mercadería bonificada → total = isv15. Legítimo.
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => 'F-BONIF',
            'total' => 166.85,
            'isv15' => 166.85,
            'isv18' => 0,
        ]);

        $user = User::factory()->create();
        $payload = Crypt::encryptString(json_encode([]));

        $response = $this->actingAs($user)
            ->get('/imprimir/reportes/manifiestos-sin-isv?payload='.urlencode($payload));

        $response->assertOk();

        // ISV total = 30.00 + 166.85 = 196.85 — el ISV de la bonificación
        // SE INCLUYE completo (no se excluye por "parecer imposible").
        $response->assertSee('196.85');

        // Total sin ISV = (230.00 + 166.85) − 196.85 = 200.00: el neto de
        // la bonificación es 0 porque todo lo cobrado es impuesto.
        // (Un "saneo" que excluyera su ISV daría 366.85 — incorrecto.)
        $response->assertSee('200.00');
        $response->assertDontSee('366.85');
    }
}
