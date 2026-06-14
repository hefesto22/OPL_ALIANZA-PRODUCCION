<?php

namespace Tests\Feature\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReturnReasonSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica que el seeder de demo produce datos consistentes:
 *   - 5 manifiestos (900001–900005) y 25 facturas.
 *   - Devoluciones reales de los 3 tipos (caja, sueltas, mixta).
 *   - Totales del manifiesto recalculados (no quedan en 0).
 *   - Es idempotente (correrlo dos veces no duplica).
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reglas de fecha en su default de producción (mezcla permitida, etc.).
        config([
            'manifests.dates.reject_mixed_dates' => false,
            'manifests.dates.manifest_date_source' => 'upload',
            'manifests.dates.max_backdate_days' => 30,
        ]);

        // Datos de referencia que el seeder de demo necesita.
        $this->seed([WarehouseSeeder::class, SupplierSeeder::class, ReturnReasonSeeder::class]);
        User::factory()->create();
    }

    public function test_seeder_creates_five_manifests_with_invoices(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(5, Manifest::whereIn('number', ['900001', '900002', '900003', '900004', '900005'])->count());

        $manifestIds = Manifest::whereIn('number', ['900001', '900002', '900003', '900004', '900005'])->pluck('id');
        $this->assertSame(25, Invoice::whereIn('manifest_id', $manifestIds)->count());
    }

    public function test_seeder_creates_returns_of_all_three_types(): void
    {
        $this->seed(DemoDataSeeder::class);

        // Caja completa: cajas > 0, sueltas = 0.
        $this->assertTrue(
            ReturnLine::where('quantity_box', '>', 0)->where('quantity', 0)->exists(),
            'Falta una devolución de caja completa.'
        );

        // Unidades sueltas: cajas = 0, sueltas > 0.
        $this->assertTrue(
            ReturnLine::where('quantity_box', 0)->where('quantity', '>', 0)->exists(),
            'Falta una devolución de unidades sueltas.'
        );

        // Mixta: cajas > 0 y sueltas > 0.
        $this->assertTrue(
            ReturnLine::where('quantity_box', '>', 0)->where('quantity', '>', 0)->exists(),
            'Falta una devolución mixta (caja + sueltas).'
        );

        // Todas auto-aprobadas (flujo absoluto).
        $this->assertGreaterThanOrEqual(12, InvoiceReturn::where('status', 'approved')->count());

        // La demo debe mostrar AMBOS tipos: parcial y total.
        $this->assertTrue(InvoiceReturn::where('type', 'partial')->exists(), 'Falta una devolución parcial.');
        $this->assertTrue(InvoiceReturn::where('type', 'total')->exists(), 'Falta una devolución total.');
    }

    public function test_seeder_recalculates_manifest_totals(): void
    {
        $this->seed(DemoDataSeeder::class);

        $manifest = Manifest::where('number', '900001')->first();

        $this->assertNotNull($manifest);
        $this->assertGreaterThan(0, (float) $manifest->total_invoices, 'total_invoices no se recalculó.');
        $this->assertGreaterThan(0, (float) $manifest->total_returns, 'total_returns no se recalculó.');
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class); // segunda corrida

        // Sigue habiendo exactamente 5 manifiestos de demo, no 10.
        $this->assertSame(5, Manifest::whereIn('number', ['900001', '900002', '900003', '900004', '900005'])->count());
        $manifestIds = Manifest::whereIn('number', ['900001', '900002', '900003', '900004', '900005'])->pluck('id');
        $this->assertSame(25, Invoice::whereIn('manifest_id', $manifestIds)->count());
    }
}
