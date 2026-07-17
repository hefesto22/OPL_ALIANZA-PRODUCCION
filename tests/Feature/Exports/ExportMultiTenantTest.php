<?php

namespace Tests\Feature\Exports;

use App\Exports\DepositsExport;
use App\Exports\ManifestsExport;
use App\Exports\ReturnsDetailExport;
use App\Exports\ReturnsExport;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\Supplier;
use App\Models\User;
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

        // La pertenencia a bodega se define por las FACTURAS del manifiesto
        // (espejo de ManifestResource::getEloquentQuery), no por
        // manifests.warehouse_id — un manifiesto puede abarcar varias bodegas.
        Invoice::factory()->create([
            'manifest_id' => $oacManifest->id,
            'warehouse_id' => $this->oac->id,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $oasManifest->id,
            'warehouse_id' => $this->oas->id,
        ]);

        $export = new ManifestsExport(warehouseIds: [$this->oac->id]);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($oacManifest->id, $ids);
        $this->assertNotContains(
            $oasManifest->id,
            $ids,
            'Un export con warehouseId=OAC NO debe devolver manifiestos de OAS.'
        );
    }

    public function test_manifests_export_includes_multi_warehouse_manifest(): void
    {
        // Manifiesto MIXTO: facturas de OAC y OAS. Debe aparecer en el
        // export de AMBAS bodegas (regresión del fix 2026-07-17: el
        // whereIn('warehouse_id') directo lo excluía de una de las dos
        // aunque el usuario lo viera en su listado).
        $mixed = Manifest::factory()->create(['warehouse_id' => $this->oac->id]);

        Invoice::factory()->create([
            'manifest_id' => $mixed->id,
            'warehouse_id' => $this->oac->id,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $mixed->id,
            'warehouse_id' => $this->oas->id,
        ]);

        foreach ([$this->oac, $this->oas] as $warehouse) {
            $export = new ManifestsExport(warehouseIds: [$warehouse->id]);

            $this->assertContains(
                $mixed->id,
                $export->query()->pluck('id')->toArray(),
                "El manifiesto mixto debe aparecer en el export de {$warehouse->code}."
            );
        }
    }

    public function test_manifests_export_filters_by_selected_ids(): void
    {
        // Bulk action: el export incluye SOLO los manifiestos marcados.
        $selected = Manifest::factory()->create(['warehouse_id' => $this->oac->id]);
        $notSelected = Manifest::factory()->create(['warehouse_id' => $this->oac->id]);

        $export = new ManifestsExport(manifestIds: [$selected->id]);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($selected->id, $ids);
        $this->assertNotContains($notSelected->id, $ids);
    }

    public function test_manifests_export_without_warehouse_id_sees_all(): void
    {
        Manifest::factory()->create(['warehouse_id' => $this->oac->id]);
        Manifest::factory()->create(['warehouse_id' => $this->oas->id]);

        $export = new ManifestsExport; // sin warehouseId = admin ve todo

        $this->assertCount(2, $export->query()->get());
    }

    public function test_deposits_export_filters_by_visible_user_ids(): void
    {
        // Los depósitos ya no se filtran por bodega del manifiesto, sino por
        // la JERARQUÍA de creación (created_by): el export refleja solo los
        // depósitos registrados por los usuarios visibles (el que exporta +
        // su subárbol). Ver Deposit::scopeVisibleTo.
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->oac->id]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $depositA = Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => 1000.00,
            'deposit_date' => now(),
            'bank' => 'BANPAIS',
            'created_by' => $userA->id,
        ]);
        $depositB = Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => 2000.00,
            'deposit_date' => now(),
            'bank' => 'FICOHSA',
            'created_by' => $userB->id,
        ]);

        $export = new DepositsExport(visibleUserIds: [$userA->id]);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($depositA->id, $ids);
        $this->assertNotContains(
            $depositB->id,
            $ids,
            'Un depósito creado por un usuario fuera del subárbol NO debe '.
            'aparecer en el export.'
        );
    }

    public function test_returns_export_filters_by_warehouse(): void
    {
        $oacReturn = InvoiceReturn::factory()->create(['warehouse_id' => $this->oac->id]);
        $oasReturn = InvoiceReturn::factory()->create(['warehouse_id' => $this->oas->id]);

        $export = new ReturnsExport(warehouseIds: [$this->oac->id]);
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

        $export = new ReturnsDetailExport(warehouseIds: [$this->oac->id]);
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
