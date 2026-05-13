<?php

namespace Tests\Unit\Architecture;

use Tests\TestCase;

/**
 * Test arquitectural — contrato de defaults de infraestructura.
 *
 * ¿Qué protege este test?
 *
 * El proyecto usa Redis para queue, cache y session (Horizon + escala 10k
 * facturas/día). Los archivos `config/*.php` declaran un fallback como
 * segundo argumento de `env(...)`, que SOLO aplica si la key falta en
 * `.env`. Históricamente esos fallbacks venían como `'database'` en los
 * templates de Laravel — una trampa silenciosa: si un deploy nuevo olvida
 * configurar `.env`, el sistema arranca con backends incorrectos
 * (sessions en DB → contención, queue en DB → tabla inflada, cache en DB
 * → caché que no cachea). El bug solo aparece bajo carga.
 *
 * Este test fija los defaults canónicos en código. Si alguien revierte
 * uno, el test rojo lo caza antes del merge.
 *
 * Nota: el test lee la SOURCE de los archivos `config/*.php` directamente
 * (no `config('queue.default')`) porque en el entorno de testing
 * `phpunit.xml` sobrescribe estos valores a `sync`/`array`/`array` para
 * tests aislados. Validar contra `config()` falsearía la verificación.
 */
class ConfigDefaultsTest extends TestCase
{
    public function test_queue_default_falls_back_to_redis_when_env_missing(): void
    {
        $this->assertFallbackIsRedis(
            'config/queue.php',
            "env('QUEUE_CONNECTION'",
            'queue'
        );
    }

    public function test_cache_default_falls_back_to_redis_when_env_missing(): void
    {
        $this->assertFallbackIsRedis(
            'config/cache.php',
            "env('CACHE_STORE'",
            'cache'
        );
    }

    public function test_session_default_falls_back_to_redis_when_env_missing(): void
    {
        $this->assertFallbackIsRedis(
            'config/session.php',
            "env('SESSION_DRIVER'",
            'session'
        );
    }

    public function test_sanctum_token_expiration_is_finite(): void
    {
        // Contrato: los tokens API NUNCA deben ser eternos. Si un token se
        // filtra, la ventana de daño debe estar acotada. El valor default
        // en código (sin .env) DEBE ser finito (no null).
        $contents = file_get_contents(base_path('config/sanctum.php'));

        // Pattern: 'expiration' => env('SANCTUM_EXPIRATION', <número>),
        $this->assertMatchesRegularExpression(
            "/'expiration'\s*=>\s*env\(\s*'SANCTUM_EXPIRATION'\s*,\s*(?<minutes>\d+)\s*\)/",
            $contents,
            "config/sanctum.php debe declarar `'expiration' => env('SANCTUM_EXPIRATION', <minutes>)`. ".
            'Default null = tokens eternos = ventana de daño infinita si uno se filtra.'
        );

        preg_match(
            "/'expiration'\s*=>\s*env\(\s*'SANCTUM_EXPIRATION'\s*,\s*(?<minutes>\d+)\s*\)/",
            $contents,
            $matches
        );

        $minutes = (int) $matches['minutes'];

        // Sanity: entre 1 día (1440 min) y 2 años (1051200 min).
        $this->assertGreaterThanOrEqual(
            1440,
            $minutes,
            "Sanctum expiration de {$minutes} minutos es < 1 día — demasiado agresivo, rompe integraciones."
        );
        $this->assertLessThanOrEqual(
            1051200,
            $minutes,
            "Sanctum expiration de {$minutes} minutos es > 2 años — defeats the purpose, prácticamente eterno."
        );
    }

    public function test_logging_has_domain_separated_channels(): void
    {
        // Contrato: debe haber channels separados por dominio para que el
        // debugging a las 3am sea quirúrgico, no `grep` sobre un archivo
        // gigante con eventos de 50 dominios entrelazados.
        $requiredChannels = [
            'jobs' => 'Logs de jobs en Horizon (failures, retries, contexto).',
            'api' => 'Logs de endpoints /api/v1/* y middleware de auth.',
            'imports' => 'Logs de importadores (Jaremar API, manifest JSON).',
            'security' => 'Logs de auth, autorización, intentos fallidos.',
        ];

        $configuredChannels = array_keys(config('logging.channels'));

        foreach ($requiredChannels as $channel => $proposito) {
            $this->assertContains(
                $channel,
                $configuredChannels,
                "Falta el channel '{$channel}' en config/logging.php. ".
                "Propósito: {$proposito} ".
                'Sin este channel separado, los logs de este dominio se mezclan con '.
                'todos los demás y diagnosticar problemas se vuelve grep en pajar.'
            );

            // Cada channel debe tener un path único en storage/logs/
            $channelConfig = config("logging.channels.{$channel}");
            $this->assertArrayHasKey('path', $channelConfig, "Channel '{$channel}' debe declarar `path`");
            $this->assertStringContainsString(
                "logs/{$channel}",
                $channelConfig['path'],
                "Channel '{$channel}' debe escribir a storage/logs/{$channel}.log para separación visual."
            );
        }
    }

    public function test_activity_log_retention_aligns_with_schedule(): void
    {
        // Contrato: el TTL del config DEBE coincidir con el --days que el
        // schedule pasa al comando `activitylog:prune`. Tenerlos desincronizados
        // (config=365, schedule=90) confunde a ops y al próximo dev.
        $configContents = file_get_contents(base_path('config/activitylog.php'));
        $scheduleContents = file_get_contents(base_path('routes/console.php'));

        // Extraer el TTL del config
        $this->assertMatchesRegularExpression(
            "/'delete_records_older_than_days'\s*=>\s*(?<days>\d+)/",
            $configContents,
            'config/activitylog.php debe declarar delete_records_older_than_days'
        );
        preg_match(
            "/'delete_records_older_than_days'\s*=>\s*(?<days>\d+)/",
            $configContents,
            $configMatch
        );

        // Extraer el --days del schedule
        $this->assertMatchesRegularExpression(
            '/activitylog:prune --days=(?<days>\d+)/',
            $scheduleContents,
            'routes/console.php debe agendar `activitylog:prune --days=N`'
        );
        preg_match(
            '/activitylog:prune --days=(?<days>\d+)/',
            $scheduleContents,
            $scheduleMatch
        );

        $this->assertSame(
            (int) $configMatch['days'],
            (int) $scheduleMatch['days'],
            "Inconsistencia: config/activitylog.php declara {$configMatch['days']} días, ".
            "routes/console.php pasa --days={$scheduleMatch['days']}. ".
            'Alinear ambos al mismo valor.'
        );

        // Sanity: 30-180 días es el rango razonable para auditoría operativa.
        $days = (int) $configMatch['days'];
        $this->assertGreaterThanOrEqual(30, $days, "TTL de {$days} días < 30 = pérdida temprana de evidencia regulatoria");
        $this->assertLessThanOrEqual(180, $days, "TTL de {$days} días > 180 = tabla activity_log infla sin razón operativa");
    }

    /**
     * Verifica que en el archivo dado, la llamada env(...) con el key
     * indicado tiene 'redis' como fallback (no 'database', no 'file').
     *
     * Acepta espacios variables y entrecomillado simple o doble.
     */
    private function assertFallbackIsRedis(string $relativePath, string $envCallPrefix, string $contexto): void
    {
        $absolutePath = base_path($relativePath);
        $this->assertFileExists($absolutePath, "No se encontró {$relativePath}");

        $contents = file_get_contents($absolutePath);

        // Patrón: env('KEY', 'redis')  con tolerancia a espacios/comillas
        $escapedPrefix = preg_quote($envCallPrefix, '/');
        $pattern = '/'.$escapedPrefix.'\s*,\s*[\'"](?<fallback>[^\'"]+)[\'"]/';

        $this->assertMatchesRegularExpression(
            $pattern,
            $contents,
            "No se encontró el patrón {$envCallPrefix}, '...') en {$relativePath}"
        );

        preg_match($pattern, $contents, $matches);

        $this->assertSame(
            'redis',
            $matches['fallback'],
            "El fallback de {$contexto} en {$relativePath} es '{$matches['fallback']}'. ".
            "Debe ser 'redis' por contrato del proyecto (Horizon, escala 10k facturas/día). ".
            'Si el cambio es intencional por una razón documentada, actualizar este test '.
            'en el mismo PR para mantenerlo como contrato vivo.'
        );
    }
}
