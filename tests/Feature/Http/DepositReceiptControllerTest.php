<?php

namespace Tests\Feature\Http;

use App\Models\Deposit;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests del DepositReceiptController.
 *
 * Cuatro capas de defensa:
 *  1. middleware `signed` (TTL 30min) — link firmado y no expirado.
 *  2. middleware `auth` — sesión activa.
 *  3. Gate::authorize('view', $deposit) en el controller — DepositPolicy::view
 *     valida userOwnsRecordViaRelation('manifest'). Aislamiento por bodega.
 *  4. Check de archivo físico — 404 si el archivo no está en disco.
 *
 * Cada test cubre la negativa de una capa para que el contrato quede
 * congelado en CI.
 */
class DepositReceiptControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $warehouseUserOAC;

    protected Warehouse $warehouseOAC;

    protected Warehouse $warehouseOAS;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $encargadoRole = Role::create(['name' => 'encargado', 'guard_name' => 'web']);

        $perms = ['ViewAny:Deposit', 'View:Deposit'];
        foreach ($perms as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        $superAdminRole->givePermissionTo($perms);
        $adminRole->givePermissionTo($perms);
        $encargadoRole->givePermissionTo($perms);

        Supplier::factory()->create(['is_active' => true]);

        $this->warehouseOAC = Warehouse::factory()->oac()->create();
        $this->warehouseOAS = Warehouse::factory()->oas()->create();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->warehouseUserOAC = User::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $this->warehouseUserOAC->assignRole('encargado');
    }

    /**
     * Helper: crea un deposit con un archivo de receipt válido en disco fake.
     */
    private function createDepositWithReceipt(int $warehouseId): Deposit
    {
        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouseId]);
        $deposit = Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'receipt_image' => 'deposits/receipts/test-'.uniqid().'.jpg',
        ]);
        Storage::disk('local')->put($deposit->receipt_image, 'fake-jpeg-bytes');

        return $deposit;
    }

    public function test_returns_200_for_admin_with_valid_signed_url(): void
    {
        $deposit = $this->createDepositWithReceipt($this->warehouseOAC->id);

        $url = URL::temporarySignedRoute(
            'deposits.receipt',
            now()->addMinutes(30),
            ['deposit' => $deposit->id]
        );

        $response = $this->actingAs($this->admin)->get($url);

        $response->assertOk();
    }

    public function test_returns_403_when_signed_url_is_missing(): void
    {
        // URL plain sin firma — el middleware `signed` rechaza con 403
        // antes de llegar al controller (no se invoca la Policy siquiera).
        $deposit = $this->createDepositWithReceipt($this->warehouseOAC->id);

        $response = $this->actingAs($this->admin)
            ->get(route('deposits.receipt', $deposit));

        $response->assertForbidden();
    }

    public function test_returns_403_when_signed_url_has_expired(): void
    {
        // TTL 30min es la regla del producto; aquí simulamos vencimiento
        // viajando 31min al futuro. Si el TTL cambia en el modelo, este
        // test sigue siendo válido porque genera el URL con su propio TTL.
        $deposit = $this->createDepositWithReceipt($this->warehouseOAC->id);

        $url = URL::temporarySignedRoute(
            'deposits.receipt',
            now()->addMinutes(30),
            ['deposit' => $deposit->id]
        );

        Carbon::setTestNow(now()->addMinutes(31));

        try {
            $response = $this->actingAs($this->admin)->get($url);
            $response->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_returns_403_when_warehouse_user_accesses_deposit_from_other_warehouse(): void
    {
        // El operador OAC NO puede ver el comprobante de un depósito que
        // pertenece a un manifest de OAS, aunque la URL esté firmada
        // correctamente. La Policy bloquea vía userOwnsRecordViaRelation.
        $deposit = $this->createDepositWithReceipt($this->warehouseOAS->id);

        $url = URL::temporarySignedRoute(
            'deposits.receipt',
            now()->addMinutes(30),
            ['deposit' => $deposit->id]
        );

        $response = $this->actingAs($this->warehouseUserOAC)->get($url);

        $response->assertForbidden();
    }

    public function test_returns_200_when_warehouse_user_accesses_deposit_from_own_warehouse(): void
    {
        // Confirmación del happy path para warehouse user: si el deposit
        // pertenece a su bodega, la Policy lo aprueba y entra normal.
        $deposit = $this->createDepositWithReceipt($this->warehouseOAC->id);

        $url = URL::temporarySignedRoute(
            'deposits.receipt',
            now()->addMinutes(30),
            ['deposit' => $deposit->id]
        );

        $response = $this->actingAs($this->warehouseUserOAC)->get($url);

        $response->assertOk();
    }

    public function test_returns_404_when_deposit_has_no_receipt_attached(): void
    {
        // Deposit existe pero sin receipt_image — la Policy pasa (el
        // usuario tiene acceso al deposit), pero el controller responde
        // 404 con mensaje explicativo.
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $deposit = Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'receipt_image' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'deposits.receipt',
            now()->addMinutes(30),
            ['deposit' => $deposit->id]
        );

        $response = $this->actingAs($this->admin)->get($url);

        $response->assertNotFound();
    }
}
