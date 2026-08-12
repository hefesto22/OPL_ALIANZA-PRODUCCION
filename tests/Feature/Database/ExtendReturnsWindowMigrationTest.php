<?php

namespace Tests\Feature\Database;

use App\Models\Manifest;
use App\Support\BusinessDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfill de la ventana de devoluciones (5 → 7 días hábiles, 2026-08-12).
 *
 * Esta migración toca datos de PRODUCCIÓN y su valor está en lo que NO
 * hace. Las tres reglas se fijan aquí porque olvidarlas cuesta caro:
 *
 *   - Reabrir un manifiesto CERRADO le agrega devoluciones a un paquete
 *     que Jaremar ya consumió como completo e inmutable: quedan colgando
 *     de fechas de emisión que probablemente ya no vuelve a consultar.
 *   - Rellenar un NULL ("sin límite", transición 2026-07-21) le pone plazo
 *     a un backlog que se acordó registrar sin plazo.
 *   - La excepción 796008 (bodega que no alcanzó a registrar) es puntual y
 *     con fecha fija: no es el cálculo de 7 hábiles, que ya no la alcanza.
 */
class ExtendReturnsWindowMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_12_184051_extend_returns_window_to_seven_business_days.php';

    protected function setUp(): void
    {
        parent::setUp();

        // El TestCase base desactiva la ventana (10000) para el resto de
        // suites; el backfill se prueba con la regla real.
        config(['api.devoluciones_ventana_dias_habiles' => 7]);
    }

    /**
     * Corre la migración tal cual la ejecuta `php artisan migrate` en
     * producción — el archivo real, no una copia de su lógica.
     */
    private function correrBackfill(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function conDeadline(Manifest $manifest, ?string $deadline): Manifest
    {
        DB::table('manifests')
            ->where('id', $manifest->id)
            ->update(['returns_deadline_at' => $deadline]);

        return $manifest->fresh();
    }

    public function test_manifiesto_abierto_se_extiende_a_siete_dias_habiles(): void
    {
        $hoy = now()->toDateString();

        $manifest = $this->conDeadline(
            Manifest::factory()->create(['date' => $hoy]),
            BusinessDays::deadline($hoy, 5)->format('Y-m-d H:i:s'), // ventana vieja, aún abierta
        );

        $this->correrBackfill();

        $this->assertSame(
            BusinessDays::deadline($hoy, 7)->format('Y-m-d H:i:s'),
            $manifest->fresh()->returns_deadline_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_backfill_es_idempotente(): void
    {
        $hoy = now()->toDateString();
        $manifest = Manifest::factory()->create(['date' => $hoy]);

        $this->correrBackfill();
        $primera = $manifest->fresh()->returns_deadline_at->format('Y-m-d H:i:s');

        $this->correrBackfill();

        $this->assertSame($primera, $manifest->fresh()->returns_deadline_at->format('Y-m-d H:i:s'));
    }

    public function test_manifiesto_cerrado_no_se_reabre(): void
    {
        // Su paquete ya se publicó a Jaremar: extenderle la ventana dejaría
        // devoluciones nuevas fuera del rango que Jaremar vuelve a consultar.
        $cerrado = '2026-08-01 23:59:59';

        $manifest = $this->conDeadline(
            Manifest::factory()->create(['date' => now()->subDays(20)->toDateString()]),
            $cerrado,
        );

        $this->correrBackfill();

        $this->assertSame($cerrado, $manifest->fresh()->returns_deadline_at->format('Y-m-d H:i:s'));
    }

    public function test_manifiesto_sin_limite_sigue_sin_limite(): void
    {
        $manifest = $this->conDeadline(
            Manifest::factory()->create(['date' => now()->subDays(20)->toDateString()]),
            null,
        );

        $this->correrBackfill();

        $this->assertNull($manifest->fresh()->returns_deadline_at);
    }

    public function test_excepcion_796008_se_reabre_con_plazo_fijo(): void
    {
        // La bodega no alcanzó a registrar sus devoluciones antes del cierre
        // (decisión Mauricio, 2026-08-12): se reabre hasta el sábado 15/08 y
        // al vencer sale en el siguiente lote que consulte Jaremar.
        $manifest = $this->conDeadline(
            Manifest::factory()->create([
                'number' => '796008',
                'date' => now()->subDays(10)->toDateString(),
            ]),
            '2026-08-10 23:59:59',
        );

        $this->correrBackfill();

        $this->assertSame(
            '2026-08-15 23:59:59',
            $manifest->fresh()->returns_deadline_at->format('Y-m-d H:i:s'),
        );
    }
}
