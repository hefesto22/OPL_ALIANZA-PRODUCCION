<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessManifestImport;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests para ProcessManifestImport job.
 *
 * Ahora que ManifestImporterService usa DB::table()->insert() en vez
 * de pg_copy_from, el job completo es testeable con RefreshDatabase.
 */
class ProcessManifestImportTest extends TestCase
{
    use RefreshDatabase;

    protected User      $user;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        Supplier::factory()->create(['is_active' => true]);
        $this->warehouse = Warehouse::factory()->oac()->create();
        $this->user      = User::factory()->create();

        Storage::fake('local');
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function invoicePayload(array $overrides = []): array
    {
        static $seq = 0;
        $seq++;

        return array_merge([
            'Id'                => $seq * 1000,
            'NumeroManifiesto'  => 'MAN-JOB-001',
            'Nfactura'          => "FJ-{$seq}",
            'FechaFactura'      => '2026-04-10',
            'Vendedorid'        => 'V01',
            'Vendedor'          => 'VENDEDOR',
            'Clienteid'         => 'C001',
            'Cliente'           => 'PULPERIA TEST',
            'Almacen'           => 'OAC',
            'Total'             => 1000.00,
            'DescuentosRebajas' => 0,
            'Isv18'             => 0,
            'Isv15'             => 0,
            'ImporteExcento'        => 0,
            'ImporteExento_Desc'    => 0,
            'ImporteExento_ISV18'   => 0,
            'ImporteExento_ISV15'   => 0,
            'ImporteExento_Total'   => 0,
            'ImporteExonerado'      => 0,
            'ImporteExonerado_Desc' => 0,
            'ImporteExonerado_ISV18'=> 0,
            'ImporteExonerado_ISV15'=> 0,
            'ImporteExonerado_Total'=> 0,
            'ImporteGrabado'        => 1000.00,
            'ImporteGravado_Desc'   => 0,
            'ImporteGravado_ISV18'  => 0,
            'ImporteGravado_ISV15'  => 0,
            'ImporteGravado_Total'  => 1000.00,
            'LineasFactura' => [
                [
                    'Id' => $seq * 10000 + 1, 'InvoiceId' => $seq * 1000,
                    'NumeroLinea' => 1,
                    'ProductoId' => 'P-001', 'ProductoDesc' => 'PRODUCTO JOB',
                    'CantidadFracciones' => 10.0, 'CantidadDecimal' => 1.0,
                    'CantidadCaja' => 1.0, 'CantidadUnidadMinVenta' => 10.0,
                    'FactorConversion' => 10,
                    'Costo' => 80.0, 'Precio' => 100.0,
                    'Subtotal' => 1000.0, 'Total' => 1000.0,
                ],
            ],
        ], $overrides);
    }

    private function storeJsonFile(array $invoices, string $path = 'imports/test.json'): string
    {
        Storage::disk('local')->put($path, json_encode($invoices));
        return $path;
    }

    // ═══════════════════════════════════════════════════════════════
    //  Happy path
    // ═══════════════════════════════════════════════════════════════

    public function test_job_creates_manifest_with_invoices_and_lines(): void
    {
        $inv1 = $this->invoicePayload(['Id' => 2001, 'Nfactura' => 'FJ-A', 'Total' => 500]);
        $inv2 = $this->invoicePayload(['Id' => 2002, 'Nfactura' => 'FJ-B', 'Total' => 800]);
        $path = $this->storeJsonFile([$inv1, $inv2]);

        $job = new ProcessManifestImport($path, $this->user->id, 'test.json');
        $job->handle(app(\App\Services\ManifestImporterService::class));

        // Manifest created
        $manifest = Manifest::where('number', 'MAN-JOB-001')->first();
        $this->assertNotNull($manifest);
        $this->assertSame('imported', $manifest->status);

        // 2 invoices
        $invoiceCount = DB::table('invoices')->where('manifest_id', $manifest->id)->count();
        $this->assertSame(2, $invoiceCount);

        // 2 lines (1 per invoice)
        $lineCount = DB::table('invoice_lines')
            ->whereIn('invoice_id', DB::table('invoices')->where('manifest_id', $manifest->id)->pluck('id'))
            ->count();
        $this->assertSame(2, $lineCount);
    }

    public function test_job_recalculates_manifest_totals(): void
    {
        $inv = $this->invoicePayload(['Total' => 1500]);
        $path = $this->storeJsonFile([$inv]);

        $job = new ProcessManifestImport($path, $this->user->id, 'test.json');
        $job->handle(app(\App\Services\ManifestImporterService::class));

        $manifest = Manifest::where('number', 'MAN-JOB-001')->first();

        // recalculateTotals actualiza invoices_count y total_invoices
        $this->assertSame(1, $manifest->invoices_count);
        $this->assertEquals(1500.00, (float) $manifest->total_invoices);
    }

    public function test_job_deletes_json_file_after_success(): void
    {
        $path = $this->storeJsonFile([$this->invoicePayload()]);
        Storage::disk('local')->assertExists($path);

        $job = new ProcessManifestImport($path, $this->user->id, 'test.json');
        $job->handle(app(\App\Services\ManifestImporterService::class));

        Storage::disk('local')->assertMissing($path);
    }

    public function test_job_sends_success_notification(): void
    {
        $path = $this->storeJsonFile([$this->invoicePayload()]);

        $job = new ProcessManifestImport($path, $this->user->id, 'test.json');
        $job->handle(app(\App\Services\ManifestImporterService::class));

        // Filament sendToDatabase crea registros en la tabla notifications
        $notifications = DB::table('notifications')
            ->where('notifiable_id', $this->user->id)
            ->get();

        $this->assertTrue($notifications->count() >= 1);

        $data = json_decode($notifications->first()->data, true);
        $this->assertStringContainsString('importado', $data['body'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Unknown warehouses
    // ═══════════════════════════════════════════════════════════════

    public function test_job_notifies_admins_about_unknown_warehouses(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $inv = $this->invoicePayload(['Almacen' => 'ZZZ']);
        $path = $this->storeJsonFile([$inv]);

        $job = new ProcessManifestImport($path, $this->user->id, 'test.json');
        $job->handle(app(\App\Services\ManifestImporterService::class));

        // El admin debe recibir notificación de bodegas desconocidas
        $adminNotifications = DB::table('notifications')
            ->where('notifiable_id', $admin->id)
            ->get();

        $this->assertTrue($adminNotifications->count() >= 1);

        $data = json_decode($adminNotifications->first()->data, true);
        $this->assertStringContainsString('ZZZ', $data['body'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Error handling
    // ═══════════════════════════════════════════════════════════════

    public function test_job_sends_error_notification_on_invalid_json(): void
    {
        Storage::disk('local')->put('imports/bad.json', 'not valid json!!!');

        $job = new ProcessManifestImport('imports/bad.json', $this->user->id, 'bad.json');

        try {
            $job->handle(app(\App\Services\ManifestImporterService::class));
        } catch (\Throwable $e) {
            // El job llama $this->fail() que lanza excepción en sync
        }

        $notifications = DB::table('notifications')
            ->where('notifiable_id', $this->user->id)
            ->get();

        $hasError = $notifications->contains(function ($n) {
            $data = json_decode($n->data, true);
            return str_contains($data['body'] ?? '', 'bad.json');
        });

        $this->assertTrue($hasError);
    }

    public function test_job_sends_error_notification_when_no_active_supplier(): void
    {
        // Desactivar el supplier creado en setUp
        Supplier::query()->update(['is_active' => false]);

        $path = $this->storeJsonFile([$this->invoicePayload()]);

        $job = new ProcessManifestImport($path, $this->user->id, 'test.json');

        try {
            $job->handle(app(\App\Services\ManifestImporterService::class));
        } catch (\Throwable $e) {
            // Expected
        }

        $notifications = DB::table('notifications')
            ->where('notifiable_id', $this->user->id)
            ->get();

        $hasError = $notifications->contains(function ($n) {
            $data = json_decode($n->data, true);
            return str_contains($data['body'] ?? '', 'proveedor activo');
        });

        $this->assertTrue($hasError);
    }
}
