<?php

namespace Tests\Feature\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\ReturnReason;
use App\Models\User;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Integration tests para ReturnService::createReturn().
 *
 * Este método maneja devoluciones, que son el flujo de dinero más crítico
 * del sistema (lo que se devuelve al cliente sale del total a depositar).
 * Por eso los tests golpean la BD real (Postgres) con RefreshDatabase y no
 * mockean nada — si la query de recálculo o el CHECK constraint del status
 * se rompe, estos tests lo detectan antes que producción.
 *
 * Convenciones de los fixtures:
 * - InvoiceLineFactory default produce 10 cajas × factor 12 × Q10 = Q1,200
 *   (ver InvoiceLineFactory::definition para la justificación)
 * - Los tests sobrescriben Invoice::total para que coincida con los
 *   line_totals, de forma que "devolver todo" sea una operación exacta.
 */
class ReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReturnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReturnService::class);
    }

    /**
     * Construye una factura con N líneas de cantidad fija y el total ajustado
     * a la suma de las líneas. Devuelve la factura cargada con sus líneas.
     */
    private function makeInvoiceWithLines(int $lineCount = 1, int $boxesPerLine = 10, float $pricePerUnit = 10.0): Invoice
    {
        $manifest = Manifest::factory()->create();

        $invoice = Invoice::factory()
            ->for($manifest, 'manifest')
            ->for($manifest->warehouse, 'warehouse')
            ->create([
                // Total de la factura = N líneas × (boxes × 12 × precio unitario)
                'total' => $lineCount * $boxesPerLine * 12 * $pricePerUnit,
            ]);

        InvoiceLine::factory()
            ->count($lineCount)
            ->for($invoice, 'invoice')
            ->withQuantity($boxesPerLine, $pricePerUnit)
            ->create();

        return $invoice->load('lines');
    }

    /**
     * Datos mínimos para crear una devolución con N cajas de la primera línea.
     */
    private function returnPayload(Invoice $invoice, int $boxesToReturn, ?User $user = null): array
    {
        $reason = ReturnReason::factory()->create();
        $user ??= User::factory()->create();
        $firstLine = $invoice->lines->first();

        return [
            'invoice_id'       => $invoice->id,
            'return_reason_id' => $reason->id,
            'return_date'      => now()->toDateString(),
            'created_by'       => $user->id,
            'lines'            => [
                [
                    'invoice_line_id'     => $firstLine->id,
                    'line_number'         => $firstLine->line_number,
                    'product_id'          => $firstLine->product_id,
                    'product_description' => $firstLine->product_description,
                    'quantity_box'        => $boxesToReturn,
                    'quantity'            => 0,
                ],
            ],
        ];
    }

    public function test_creates_partial_return_with_approved_status(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        // Invoice total: 1 línea × Q1200 = Q1200. Devolvemos 3 cajas = Q360.
        $data = $this->returnPayload($invoice, boxesToReturn: 3);

        $return = $this->service->createReturn($data);

        $this->assertInstanceOf(InvoiceReturn::class, $return);
        // Las devoluciones son absolutas: nacen aprobadas (ver
        // project_returns_lifecycle.md en auto-memory).
        $this->assertSame('approved', $return->status);
        $this->assertSame('partial', $return->type);
        $this->assertEqualsWithDelta(360.0, (float) $return->total, 0.01);
        $this->assertSame($invoice->id, $return->invoice_id);
        $this->assertSame($invoice->manifest_id, $return->manifest_id);
    }

    public function test_creates_return_lines_with_server_side_line_total(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        $data    = $this->returnPayload($invoice, boxesToReturn: 5);

        $return = $this->service->createReturn($data);

        $this->assertCount(1, $return->lines);
        $line = $return->lines->first();
        $this->assertSame(5.0, (float) $line->quantity_box);
        // 5 cajas × 12 × Q10 = Q600
        $this->assertEqualsWithDelta(600.0, (float) $line->line_total, 0.01);
    }

    public function test_creates_total_return_when_amount_matches_invoice_total(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        // 10 cajas = toda la línea = Q1200 = total de la factura
        $data = $this->returnPayload($invoice, boxesToReturn: 10);

        $return = $this->service->createReturn($data);

        $this->assertSame('total', $return->type);
        $this->assertEqualsWithDelta(1200.0, (float) $return->total, 0.01);
    }

    public function test_updates_invoice_status_to_partial_return(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        $data    = $this->returnPayload($invoice, boxesToReturn: 3);

        $this->service->createReturn($data);

        $invoice->refresh();
        $this->assertSame('partial_return', $invoice->status);
    }

    public function test_updates_invoice_status_to_returned_when_fully_returned(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        $data    = $this->returnPayload($invoice, boxesToReturn: 10);

        $this->service->createReturn($data);

        $invoice->refresh();
        $this->assertSame('returned', $invoice->status);
    }

    public function test_recalculates_manifest_totals_after_return(): void
    {
        $invoice  = $this->makeInvoiceWithLines();
        $manifest = $invoice->manifest;
        $manifest->recalculateTotals();  // estado inicial

        $this->assertEqualsWithDelta(1200.0, (float) $manifest->fresh()->total_invoices, 0.01);

        $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 3));

        $manifest->refresh();
        $this->assertEqualsWithDelta(360.0, (float) $manifest->total_returns, 0.01);
        // total_to_deposit = total_invoices - total_returns
        $this->assertEqualsWithDelta(840.0, (float) $manifest->total_to_deposit, 0.01);
        $this->assertSame(1, $manifest->returns_count);
    }

    public function test_invalidates_devoluciones_cache_for_today(): void
    {
        $invoice   = $this->makeInvoiceWithLines();
        $today     = now()->toDateString();
        $cacheKey  = "devoluciones:version:{$today}";
        Cache::forget($cacheKey);
        Cache::put($cacheKey, 5);

        $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 2));

        $this->assertSame(6, (int) Cache::get($cacheKey));
    }

    public function test_rejects_return_when_quantity_exceeds_available(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        // Línea tiene 10 cajas disponibles → pedimos 11
        $data = $this->returnPayload($invoice, boxesToReturn: 11);

        $this->expectException(ValidationException::class);

        try {
            $this->service->createReturn($data);
        } catch (ValidationException $e) {
            // Nada debe haberse persistido (transacción revertida)
            $this->assertSame(0, InvoiceReturn::count());
            $this->assertSame(0, ReturnLine::count());
            throw $e;
        }
    }

    public function test_rejects_return_when_manifest_is_closed(): void
    {
        $manifest = Manifest::factory()->closed()->create();
        $invoice  = Invoice::factory()
            ->for($manifest, 'manifest')
            ->for($manifest->warehouse, 'warehouse')
            ->create(['total' => 1200]);
        InvoiceLine::factory()
            ->for($invoice, 'invoice')
            ->withQuantity(10, 10.0)
            ->create();
        $invoice->load('lines');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('manifiesto está cerrado');

        $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 3));
    }

    public function test_ignores_lines_with_zero_quantity(): void
    {
        $invoice   = $this->makeInvoiceWithLines(lineCount: 2);
        $reason    = ReturnReason::factory()->create();
        $user      = User::factory()->create();
        $firstLine = $invoice->lines[0];
        $secondLine = $invoice->lines[1];

        $data = [
            'invoice_id'       => $invoice->id,
            'return_reason_id' => $reason->id,
            'return_date'      => now()->toDateString(),
            'created_by'       => $user->id,
            'lines'            => [
                [
                    'invoice_line_id'     => $firstLine->id,
                    'line_number'         => $firstLine->line_number,
                    'product_id'          => $firstLine->product_id,
                    'product_description' => $firstLine->product_description,
                    'quantity_box'        => 3,
                    'quantity'            => 0,
                ],
                [
                    // Esta línea NO debe crear ReturnLine porque qty = 0
                    'invoice_line_id'     => $secondLine->id,
                    'line_number'         => $secondLine->line_number,
                    'product_id'          => $secondLine->product_id,
                    'product_description' => $secondLine->product_description,
                    'quantity_box'        => 0,
                    'quantity'            => 0,
                ],
            ],
        ];

        $return = $this->service->createReturn($data);

        $this->assertCount(1, $return->lines);
        $this->assertSame($firstLine->id, $return->lines->first()->invoice_line_id);
    }

    public function test_cumulative_partials_become_total_when_reaching_invoice_amount(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        // 1a devolución: 4 cajas (Q480). Pendiente después = Q720 → parcial.
        $first = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 4));
        $this->assertSame('partial', $first->type);

        // 2a devolución: 6 cajas (Q720) = todo lo que queda pendiente → total.
        $second = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 6));
        $this->assertSame('total', $second->type);

        // Invoice queda totalmente devuelta.
        $invoice->refresh();
        $this->assertSame('returned', $invoice->status);
    }

    // ═══════════════════════════════════════════════════════════════════
    // updateReturn() — edición dentro de la ventana del día
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Builder para crear una devolución existente sobre la que luego
     * ejercitamos updateReturn(). Devuelve [return, invoice].
     */
    private function createExistingReturn(int $boxes = 4, int $invoiceLineCount = 1): array
    {
        $invoice = $this->makeInvoiceWithLines(lineCount: $invoiceLineCount);
        $return  = $this->service->createReturn($this->returnPayload($invoice, $boxes));
        return [$return->fresh('lines'), $invoice->fresh('lines')];
    }

    /**
     * Payload completo para updateReturn(): cada línea como array.
     */
    private function updatePayload(Invoice $invoice, array $lineQuantities): array
    {
        $lines = [];
        foreach ($invoice->lines as $i => $line) {
            $lines[] = [
                'invoice_line_id'     => $line->id,
                'line_number'         => $line->line_number,
                'product_id'          => $line->product_id,
                'product_description' => $line->product_description,
                'quantity_box'        => $lineQuantities[$i] ?? 0,
                'quantity'            => 0,
            ];
        }

        return [
            'return_reason_id' => ReturnReason::factory()->create()->id,
            'return_date'      => now()->toDateString(),
            'lines'            => $lines,
        ];
    }

    public function test_update_changes_return_reason_and_date(): void
    {
        [$return, $invoice] = $this->createExistingReturn(boxes: 3);
        $newReason = ReturnReason::factory()->create();
        $newDate   = now()->subDays(2)->toDateString();

        $data = [
            'return_reason_id' => $newReason->id,
            'return_date'      => $newDate,
            'lines'            => [[
                'invoice_line_id'     => $invoice->lines->first()->id,
                'line_number'         => $invoice->lines->first()->line_number,
                'product_id'          => $invoice->lines->first()->product_id,
                'product_description' => $invoice->lines->first()->product_description,
                'quantity_box'        => 3,
                'quantity'            => 0,
            ]],
        ];

        $updated = $this->service->updateReturn($return, $data);

        $this->assertSame($newReason->id, $updated->return_reason_id);
        $this->assertSame($newDate, $updated->return_date->toDateString());
    }

    public function test_update_modifies_line_quantities_and_recalculates_total(): void
    {
        // Devolución inicial: 3 cajas × Q120 = Q360
        [$return, $invoice] = $this->createExistingReturn(boxes: 3);
        $this->assertEqualsWithDelta(360.0, (float) $return->total, 0.01);

        // Subimos a 5 cajas → debería ser 5 × Q120 = Q600
        $updated = $this->service->updateReturn($return, $this->updatePayload($invoice, [5]));

        $this->assertCount(1, $updated->lines);
        $this->assertSame(5.0, (float) $updated->lines->first()->quantity_box);
        $this->assertEqualsWithDelta(600.0, (float) $updated->total, 0.01);
    }

    public function test_update_changes_type_from_partial_to_total_when_amount_matches(): void
    {
        // Inicial: 3 cajas = Q360 de Q1200 → partial
        [$return, $invoice] = $this->createExistingReturn(boxes: 3);
        $this->assertSame('partial', $return->type);

        // Subimos a 10 cajas = Q1200 = factura completa → total
        $updated = $this->service->updateReturn($return, $this->updatePayload($invoice, [10]));

        $this->assertSame('total', $updated->type);
        $invoice->refresh();
        $this->assertSame('returned', $invoice->status);
    }

    public function test_update_changes_type_from_total_to_partial_when_reducing_quantity(): void
    {
        // Inicial: 10 cajas = Q1200 = factura completa → total
        [$return, $invoice] = $this->createExistingReturn(boxes: 10);
        $this->assertSame('total', $return->type);

        // Bajamos a 2 cajas = Q240 → parcial
        $updated = $this->service->updateReturn($return, $this->updatePayload($invoice, [2]));

        $this->assertSame('partial', $updated->type);
        $invoice->refresh();
        $this->assertSame('partial_return', $invoice->status);
    }

    public function test_update_recalculates_manifest_totals(): void
    {
        [$return, $invoice] = $this->createExistingReturn(boxes: 3);
        $manifest = $invoice->manifest->fresh();
        $this->assertEqualsWithDelta(360.0, (float) $manifest->total_returns, 0.01);

        $this->service->updateReturn($return, $this->updatePayload($invoice, [7]));

        $manifest->refresh();
        // 7 × 12 × Q10 = Q840
        $this->assertEqualsWithDelta(840.0, (float) $manifest->total_returns, 0.01);
        $this->assertEqualsWithDelta(360.0, (float) $manifest->total_to_deposit, 0.01);
    }

    public function test_update_invalidates_devoluciones_cache(): void
    {
        [$return, $invoice] = $this->createExistingReturn(boxes: 3);
        $cacheDate = $return->processed_date instanceof \DateTimeInterface
            ? $return->processed_date->format('Y-m-d')
            : (string) $return->processed_date;
        $cacheKey  = "devoluciones:version:{$cacheDate}";
        Cache::put($cacheKey, 10);

        $this->service->updateReturn($return, $this->updatePayload($invoice, [5]));

        $this->assertSame(11, (int) Cache::get($cacheKey));
    }

    public function test_update_rejects_when_manifest_is_closed(): void
    {
        [$return, $invoice] = $this->createExistingReturn(boxes: 3);
        // Cerramos el manifiesto DESPUÉS de crear la devolución
        $invoice->manifest->update(['status' => 'closed', 'closed_at' => now()]);
        $return->load('manifest');  // refrescar la relación cacheada

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('manifiesto cerrado');

        $this->service->updateReturn($return, $this->updatePayload($invoice, [5]));
    }

    public function test_update_rejects_when_outside_edit_window(): void
    {
        // Creamos la devolución "ayer" manipulando el reloj de Carbon
        Carbon::setTestNow(now()->subDay());
        [$return, $invoice] = $this->createExistingReturn(boxes: 3);
        Carbon::setTestNow();  // volvemos a hoy

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('solo puede editarse el día');

        $this->service->updateReturn($return, $this->updatePayload($invoice, [5]));
    }

    public function test_update_rejects_when_quantity_exceeds_available_excluding_this_return(): void
    {
        // Factura con 1 línea de 10 cajas disponibles. Primera devolución
        // toma 4. Creamos una SEGUNDA devolución con 3 → quedan 3 disponibles
        // contando ambas. Al editar la segunda, solo debería poder usar
        // hasta 6 (10 - 4 de la primera), no 10.
        [$firstReturn, $invoice] = $this->createExistingReturn(boxes: 4);
        $secondReturn = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 3));
        $secondReturn = $secondReturn->fresh('lines');

        // Intentamos editar la 2a devolución para subirla a 7 cajas →
        // 7 > (10 - 4) → debe fallar.
        $this->expectException(ValidationException::class);

        $this->service->updateReturn($secondReturn, $this->updatePayload($invoice, [7]));
    }

    public function test_update_allows_reaching_the_available_limit_excluding_self(): void
    {
        // Misma setup que el test anterior: primera devolución de 4, segunda de 3.
        // La segunda debería poder subir hasta 6 (10 - 4) sin problema.
        [$firstReturn, $invoice] = $this->createExistingReturn(boxes: 4);
        $secondReturn = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 3))->fresh('lines');

        $updated = $this->service->updateReturn($secondReturn, $this->updatePayload($invoice, [6]));

        // 6 × Q120 = Q720
        $this->assertEqualsWithDelta(720.0, (float) $updated->total, 0.01);
        // La primera devolución no debe haberse tocado.
        $this->assertEqualsWithDelta(480.0, (float) $firstReturn->fresh()->total, 0.01);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cancelación (soft-delete) — el observer es quien reacciona
    // ═══════════════════════════════════════════════════════════════════

    public function test_canceling_return_recalculates_manifest_totals(): void
    {
        $invoice  = $this->makeInvoiceWithLines();
        $manifest = $invoice->manifest;
        $return   = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 5));

        // Antes de cancelar: manifest tiene una devolución de Q600
        $manifest->refresh();
        $this->assertEqualsWithDelta(600.0, (float) $manifest->total_returns, 0.01);
        $this->assertSame(1, $manifest->returns_count);

        // Cancelamos (soft-delete): el observer debe recalcular
        $return->delete();

        $manifest->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $manifest->total_returns, 0.01);
        $this->assertSame(0, $manifest->returns_count);
        $this->assertEqualsWithDelta(1200.0, (float) $manifest->total_to_deposit, 0.01);
    }

    public function test_canceling_return_reverts_invoice_status_to_imported(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        $return  = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 3));

        $invoice->refresh();
        $this->assertSame('partial_return', $invoice->status);

        $return->delete();

        $invoice->refresh();
        // Sin devoluciones aprobadas ni pendientes → vuelve a 'imported'
        $this->assertSame('imported', $invoice->status);
    }

    public function test_canceling_fully_returned_invoice_reverts_to_imported(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        // Devolución total (10 cajas = toda la factura)
        $return = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 10));

        $invoice->refresh();
        $this->assertSame('returned', $invoice->status);

        $return->delete();

        $invoice->refresh();
        $this->assertSame('imported', $invoice->status);
    }

    public function test_canceling_approved_return_invalidates_cache(): void
    {
        $invoice = $this->makeInvoiceWithLines();
        $return  = $this->service->createReturn($this->returnPayload($invoice, boxesToReturn: 4));

        // Las devoluciones nacen aprobadas con processed_date=hoy
        $this->assertSame('approved', $return->status);
        $cacheDate = $return->processed_date instanceof \DateTimeInterface
            ? $return->processed_date->format('Y-m-d')
            : (string) $return->processed_date;
        $cacheKey  = "devoluciones:version:{$cacheDate}";
        Cache::put($cacheKey, 42);

        $return->delete();

        // El observer debe haber incrementado la versión
        $this->assertSame(43, (int) Cache::get($cacheKey));
    }
}
