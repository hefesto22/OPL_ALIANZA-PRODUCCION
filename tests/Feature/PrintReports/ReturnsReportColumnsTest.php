<?php

namespace Tests\Feature\PrintReports;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\ReturnReason;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Regresión del reporte PDF de devoluciones (GET /imprimir/reportes/devoluciones).
 *
 * Bug original: la tabla de líneas mostraba una sola columna "Cant. Devuelta"
 * que leía solo `quantity` (unidades sueltas) e ignoraba `quantity_box`. Una
 * línea devuelta como cajas (quantity_box>0, quantity=0) aparecía como 0.00,
 * ocultando que sí hubo devolución.
 *
 * Fix: dos columnas separadas — "Cajas" (quantity_box) y "Unidades" (quantity),
 * igual que el Excel de 71 columnas. Este test falla contra el template viejo.
 */
class ReturnsReportColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function makeReturnWithBoxLine(): Manifest
    {
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);

        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => 'F-RPT-001',
        ]);

        $reason = ReturnReason::factory()->create([
            'code' => 'BE-03',
            'description' => 'Error de Entrega (Motorista)',
        ]);

        $return = InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'invoice_id' => $invoice->id,
            'warehouse_id' => $warehouse->id,
            'return_reason_id' => $reason->id,
            'client_id' => $invoice->client_id,
            'client_name' => $invoice->client_name,
            'return_date' => now()->toDateString().' 10:00:00',
            'processed_date' => now()->toDateString(),
            'total' => 255.00,
        ]);

        // Línea de unidades sueltas.
        ReturnLine::create([
            'return_id' => $return->id,
            'line_number' => 1,
            'product_id' => '50470402',
            'product_description' => 'CENTELLABARRA ROSADO 400GX3X4',
            'quantity_box' => 0,
            'quantity' => 5,
            'line_total' => 75.00,
        ]);

        // Línea de CAJAS — canary 7 cajas: con el template viejo saldría 0.00.
        ReturnLine::create([
            'return_id' => $return->id,
            'line_number' => 2,
            'product_id' => '50470403',
            'product_description' => 'CENTELLABARRA AMARILL 400GX3X4',
            'quantity_box' => 7,
            'quantity' => 0,
            'line_total' => 180.00,
        ]);

        return $manifest;
    }

    public function test_returns_report_shows_separate_cajas_and_unidades_columns(): void
    {
        $manifest = $this->makeReturnWithBoxLine();
        $user = User::factory()->create();

        $payload = Crypt::encryptString(json_encode(['manifest_id' => $manifest->id]));

        $response = $this->actingAs($user)
            ->get('/imprimir/reportes/devoluciones?payload='.urlencode($payload));

        $response->assertOk();

        // Estructura nueva: dos columnas separadas.
        $response->assertSee('Cajas', false);
        $response->assertSee('Unidades', false);

        // La columna única vieja ya no existe.
        $response->assertDontSee('Cant. Devuelta');

        // Dato clave: la línea de cajas renderiza su cantidad real (7.00),
        // no 0.00. Con el template viejo este 7.00 nunca aparecería.
        $response->assertSee('7.00');
    }

    public function test_returns_report_respects_configurable_row_limit(): void
    {
        // El límite ahora se lee de config('reports.max_rows'), no de env()
        // directo — para sobrevivir a `config:cache`. Con el límite en 1 y
        // 2 devoluciones en el manifiesto, debe abortar con 422.
        config(['reports.max_rows' => 1]);

        $manifest = $this->makeReturnWithBoxLine();
        $user = User::factory()->create();

        // Segunda devolución en el mismo manifiesto → 2 filas, supera el límite de 1.
        $first = InvoiceReturn::where('manifest_id', $manifest->id)->firstOrFail();
        InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'invoice_id' => $first->invoice_id,
            'warehouse_id' => $first->warehouse_id,
            'return_reason_id' => $first->return_reason_id,
            'return_date' => now()->toDateString().' 11:00:00',
            'processed_date' => now()->toDateString(),
            'total' => 10.00,
        ]);

        $payload = Crypt::encryptString(json_encode(['manifest_id' => $manifest->id]));

        $this->actingAs($user)
            ->get('/imprimir/reportes/devoluciones?payload='.urlencode($payload))
            ->assertStatus(422);
    }
}
