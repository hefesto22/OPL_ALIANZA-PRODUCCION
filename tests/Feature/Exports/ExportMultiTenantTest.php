<?php

namespace Tests\Feature\Exports;

use App\Exports\DepositsExport;
use App\Exports\ManifestsExport;
use App\Exports\ReturnsDetailExport;
use App\Exports\ReturnsExport;
use App\Models\Deposit;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de aislamiento multi-tenant en Exports.
 *
 * ¿Por qué importa?
 *
 * Hoy los Exports solo los puede gatillar super_admin/admin vía Filament
 * (ver ->visible() en ListManifests/ListDeposits/ListReturns). Están seguros
 * por visibilidad de UI.
 *
 * Pero la seguridad de UI es una línea de defensa, no la única. Estos tests
 * verifican la segunda línea: aunque alguien en el futuro abra exports a
 * encargados de bodega (caso de negocio predecible: "el encargado de OAC
 * quiere sus propios reportes"), los datos NO se filtran entre bodegas.
 *
 * Este patrón es exactamente lo que pide la regla del proyecto:
 *   "empresa B no puede ver ni modificar datos de empresa A"
 * aplicado a bodegas.
 */
class ExportMultiTenantTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oac;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        Supplier::factory()->create(['is_active' => true]);
    }

    public function test_manifests_export_filters_by_warehouse(): void
    {
        $oacManifest = Manifest::factory()->create(['warehouse_id' => $this->oac->id]);
        $oasManifest = Manifest::factory()->create(['warehouse_id' => $this->oas->id]);

        $export = new ManifestsExport(warehouseId: $this->oac->id);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($oacManifest->id, $ids);
        $this->assertNotContains(
            $oasManifest->id,
            $ids,
            'Un export con warehouseId=OAC NO debe devolver manifiestos de OAS.'
        );
    }

    public function test_manifests_export_without_warehouse_id_sees_all(): void
    {
        Manifest::factory()->create(['warehouse_id' => $this->oac->id]);
        Manifest::factory()->create(['warehouse_id' => $this->oas->id]);

        $export = new ManifestsExport; // sin warehouseId = admin ve todo

        $this->assertCount(2, $export->query()->get());
    }

    public function test_deposits_export_filters_by_warehouse_via_manifest(): void
    {
        $oacManifest = Manifest::factory()->create(['warehouse_id' => $this->oac->id]);
        $oasManifest = Manifest::factory()->create(['warehouse_id' => $this->oas->id]);

        $oacDeposit = Deposit::create([
            'manifest_id' => $oacManifest->id,
            'amount' => 1000.00,
            'deposit_date' => now(),
            'bank' => 'BANPAIS',
        ]);
        $oasDeposit = Deposit::create([
            'manifest_id' => $oasManifest->id,
            'amount' => 2000.00,
            'deposit_date' => now(),
            'bank' => 'FICOHSA',
        ]);

        $export = new DepositsExport(warehouseId: $this->oac->id);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($oacDeposit->id, $ids);
        $this->assertNotContains(
            $oasDeposit->id,
            $ids,
            'Un depósito de un manifiesto de OAS NO debe aparecer cuando se ".
            "filtra por warehouseId=OAC.'
        );
    }

    public function test_returns_export_filters_by_warehouse(): void
    {
        $oacReturn = InvoiceReturn::factory()->create(['warehouse_id' => $this->oac->id]);
        $oasReturn = InvoiceReturn::factory()->create(['warehouse_id' => $this->oas->id]);

        $export = new ReturnsExport(warehouseId: $this->oac->id);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($oacReturn->id, $ids);
        $this->assertNotContains($oasReturn->id, $ids);
    }

    public function test_returns_detail_export_filters_by_warehouse_via_return(): void
    {
        // Crear devoluciones en ambas bodegas
        $oacReturn = InvoiceReturn::factory()->create(['warehouse_id' => $this->oac->id]);
        $oasReturn = InvoiceReturn::factory()->create(['warehouse_id' => $this->oas->id]);

        // ReturnLine se filtra via whereHas('return', warehouse_id=X)
        $oacLine = ReturnLine::create([
            'return_id' => $oacReturn->id,
            'invoice_line_id' => null,
            'line_number' => 1,
            'product_id' => 'P001',
            'product_description' => 'Producto OAC',
            'quantity_box' => 1,
            'quantity' => 10,
            'line_total' => 500.00,
        ]);
        $oasLine = ReturnLine::create([
            'return_id' => $oasReturn->id,
            'invoice_line_id' => null,
            'line_number' => 1,
            'product_id' => 'P002',
            'product_description' => 'Producto OAS',
            'quantity_box' => 1,
            'quantity' => 20,
            'line_total' => 1000.00,
        ]);

        $export = new ReturnsDetailExport(warehouseId: $this->oac->id);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($oacLine->id, $ids);
        $this->assertNotContains(
            $oasLine->id,
            $ids,
            'Líneas de devolución de OAS NO deben aparecer cuando se ".
            "filtra ReturnsDetailExport por warehouseId=OAC.'
        );
    }
}
