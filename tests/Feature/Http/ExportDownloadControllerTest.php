<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Tests del ExportDownloadController.
 *
 * Defensa en profundidad: signed URL (TTL 24h, alineado con la limpieza
 * del archivo) + auth middleware + path traversal check + check de
 * existencia. Cada capa se valida con un test dedicado.
 */
class ExportDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_returns_200_for_valid_signed_url_with_authenticated_user(): void
    {
        Storage::disk('local')->put('exports/report.xlsx', 'fake-excel-bytes');

        $user = User::factory()->create();
        $url = URL::temporarySignedRoute(
            'exports.download',
            now()->addHours(24),
            ['file' => 'exports/report.xlsx']
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertOk();
    }

    public function test_returns_403_when_signed_url_is_missing(): void
    {
        // Una URL plain sin firma — el middleware `signed` la rechaza
        // antes incluso de tocar el controller.
        Storage::disk('local')->put('exports/report.xlsx', 'fake-excel-bytes');
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('exports.download', ['file' => 'exports/report.xlsx']));

        $response->assertForbidden();
    }

    public function test_returns_403_when_signed_url_has_expired(): void
    {
        // Generar URL con TTL corto y viajar al futuro más allá del expires.
        // Carbon::setTestNow es el control oficial de tiempo en Laravel; el
        // middleware `signed` compara con now() del request y rechaza.
        Storage::disk('local')->put('exports/report.xlsx', 'fake-excel-bytes');
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'exports.download',
            now()->addMinutes(5),
            ['file' => 'exports/report.xlsx']
        );

        Carbon::setTestNow(now()->addMinutes(10));

        try {
            $response = $this->actingAs($user)->get($url);
            $response->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_returns_403_for_path_traversal_even_with_valid_signature(): void
    {
        // Aunque el atacante logre firmar la URL (escenario imaginario:
        // el secreto se filtró), el controller debe seguir bloqueando
        // el path traversal. Defensa redundante intencional.
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'exports.download',
            now()->addHours(24),
            ['file' => '../../../etc/passwd']
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertForbidden();
    }

    public function test_returns_404_when_file_does_not_exist(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'exports.download',
            now()->addHours(24),
            ['file' => 'exports/nonexistent-file.xlsx']
        );

        $response = $this->actingAs($user)->get($url);

        $response->assertNotFound();
    }
}
