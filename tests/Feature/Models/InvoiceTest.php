<?php

namespace Tests\Feature\Models;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Contratos del modelo Invoice.
 *
 * Cubre los dos aspectos de la deuda 🔴 cerrada en este sprint:
 *   1. Auditoría regulatoria — el ciclo de vida de status debe quedar
 *      trazado en activity_log para responder "quién, cuándo, por qué"
 *      ante el SAR. Solo el ciclo de vida y la asignación, NO los
 *      importes fiscales (ver getActivitylogOptions para razón).
 *
 *   2. Precisión decimal en importes fiscales — todos los campos de
 *      moneda y de ISV declarados con cast decimal:2 para evitar la
 *      pérdida silenciosa de centavos en aritmética float.
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Manifest $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::factory()->create();
        $this->warehouse = Warehouse::factory()->oac()->create();
        $this->manifest = Manifest::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Auditoría (LogsActivity)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Helper: obtiene el log MÁS RECIENTE de un Invoice por id descendente.
     *
     * Usamos `orderByDesc('id')` en vez de `latest()` (que ordena por
     * `created_at`) porque en tests rápidos el factory `create()` y el
     * `update()` siguiente pueden caer en el mismo segundo, haciendo el
     * ordering por timestamp ambiguo. El `id` autoincremental es la única
     * fuente determinística de "qué log se insertó después".
     */
    private function latestInvoiceLog(Invoice $invoice): ?Activity
    {
        return Activity::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->orderByDesc('id')
            ->first();
    }

    public function test_changing_status_creates_activity_log_entry(): void
    {
        $invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'imported',
        ]);

        $logsBefore = Activity::where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->count();

        $invoice->update(['status' => 'returned']);

        $logsAfter = Activity::where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->count();

        $this->assertGreaterThan(
            $logsBefore,
            $logsAfter,
            'Cambiar status de Invoice debe generar entrada en activity_log para auditoría regulatoria'
        );

        $log = $this->latestInvoiceLog($invoice);

        $this->assertNotNull($log);
        $this->assertSame('Factura updated', $log->description);

        $changes = $log->properties->get('attributes', []);
        $this->assertSame('returned', $changes['status'] ?? null);
    }

    public function test_fiscal_amount_changes_do_not_appear_in_audit_log_attributes(): void
    {
        // El contrato regulatorio: los importes fiscales NO deben aparecer
        // como cambios visibles en activity_log. Si un auditor consulta el
        // historial de una factura, NO debe ver "importe_gravado cambió de
        // 100 a 200" mezclado con cambios reales de ciclo de vida. Esa es
        // la garantía que importa.
        //
        // Nota: NO afirmamos que Spatie no genere un log; afirmamos que el
        // contenido `attributes` del log NO contiene los campos excluidos.
        // Eso depende solo de logOnly, no de comportamiento interno de Spatie.
        $invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'imported',
            'importe_gravado' => 100.00,
            'isv15' => 15.00,
            'discounts' => 5.00,
        ]);

        $invoice->update([
            'importe_gravado' => 200.00,
            'isv15' => 30.00,
            'discounts' => 10.00,
        ]);

        // Recolectar TODOS los logs de este invoice y verificar que ninguno
        // contiene los campos excluidos en sus attributes o old.
        $excludedFields = ['importe_gravado', 'isv15', 'discounts'];

        $logs = Activity::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->get();

        foreach ($logs as $log) {
            $attributes = $log->properties->get('attributes', []);
            $old = $log->properties->get('old', []);

            foreach ($excludedFields as $field) {
                $this->assertArrayNotHasKey(
                    $field,
                    $attributes,
                    "El campo fiscal '{$field}' NO debe aparecer en activity_log.attributes ".
                    '(logOnly debe excluirlo). Es ruido para el auditor y rompe la promesa '.
                    "documentada en getActivitylogOptions. Log id={$log->id}, description='{$log->description}'."
                );
                $this->assertArrayNotHasKey(
                    $field,
                    $old,
                    "El campo fiscal '{$field}' NO debe aparecer en activity_log.old. ".
                    "Log id={$log->id}, description='{$log->description}'."
                );
            }
        }
    }

    public function test_warehouse_id_change_is_audited(): void
    {
        // Asignar una factura pending_warehouse a una bodega es una decisión
        // operativa que debe quedar registrada.
        $otraBodega = Warehouse::factory()->oas()->create();
        $invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'imported',
        ]);

        $invoice->update(['warehouse_id' => $otraBodega->id]);

        $log = $this->latestInvoiceLog($invoice);

        $this->assertNotNull($log);
        $this->assertSame('Factura updated', $log->description);

        $changes = $log->properties->get('attributes', []);
        $this->assertSame($otraBodega->id, $changes['warehouse_id'] ?? null);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Casts decimales (precisión fiscal)
    // ═══════════════════════════════════════════════════════════════

    public function test_fiscal_amount_fields_are_cast_to_decimal_strings(): void
    {
        // Contrato del cast decimal:2 — retorna string formateado con 2
        // decimales. Esto garantiza que la lectura del modelo nunca pierde
        // precisión por float (0.1 + 0.2 != 0.3 silencioso).
        $invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'importe_gravado' => 100.50,
            'importe_gravado_isv15' => 15.075,  // se redondea a 2 decimales
            'isv15' => 15.08,
            'isv18' => 0,
            'discounts' => 50.00,
        ]);

        $invoice->refresh();

        // El cast retorna string. Validamos formato y precisión.
        $this->assertIsString($invoice->importe_gravado);
        $this->assertSame('100.50', $invoice->importe_gravado);
        $this->assertSame('15.08', $invoice->importe_gravado_isv15);
        $this->assertSame('15.08', $invoice->isv15);
        $this->assertSame('0.00', $invoice->isv18);
        $this->assertSame('50.00', $invoice->discounts);
    }

    public function test_arithmetic_on_cast_fields_works_via_explicit_float(): void
    {
        // Aunque el cast retorna string, PHP coerciona automáticamente al
        // hacer operaciones numéricas. Pero el patrón correcto en este
        // proyecto es cast explícito a (float). Verificamos que ambos
        // funcionan (compatibilidad con código existente).
        $invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'total' => 1000.00,
            'isv15' => 130.43,
            'isv18' => 0,
        ]);

        $invoice->refresh();

        // Patrón canónico: cast explícito.
        $subtotal = (float) $invoice->total - (float) $invoice->isv15 - (float) $invoice->isv18;
        $this->assertEqualsWithDelta(869.57, $subtotal, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════════════

    public function test_get_net_total_attribute_handles_decimal_cast(): void
    {
        // El accessor getNetTotalAttribute hace (float) $this->total - (float) $this->total_returns.
        // Con cast decimal:2 los valores son strings — verificamos que el
        // cast explícito en el accessor preserva la precisión.
        $invoice = Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'total' => 1500.00,
            'total_returns' => 250.00,
        ]);

        $this->assertEqualsWithDelta(1250.00, $invoice->net_total, 0.001);
    }
}
