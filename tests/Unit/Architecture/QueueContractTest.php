<?php

namespace Tests\Unit\Architecture;

use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Test architectural — contrato de colas para Exports y Jobs.
 *
 * ¿Por qué este test es el más valioso del suite?
 *
 * Los tests de comportamiento prueban que HOY todo funciona. Este test prueba
 * que MAÑANA, cuando alguien agregue un nuevo Export o Job, el sistema siga
 * escalando correctamente. Es una red de seguridad estructural.
 *
 * Qué valida:
 *   1. Todo Export debe ser asíncrono (ShouldQueue) — si no, bloquea requests.
 *   2. Todo Export debe tener chunking (WithChunkReading) — sin esto, 100k
 *      filas cargan toda la tabla en memoria y tumban el worker.
 *   3. Todo Export debe declarar `$queue = 'reports'` explícitamente — sin
 *      esto cae en `default` y hace head-of-line blocking.
 *   4. Todo Job en app/Jobs debe ser ShouldQueue y declarar su cola.
 *
 * Si alguien mañana olvida cualquiera de estas reglas al crear un nuevo
 * Export/Job, este test le avisa antes de llegar a producción.
 */
class QueueContractTest extends TestCase
{
    /**
     * Colas válidas en nuestra arquitectura Horizon (ver config/horizon.php).
     */
    private const VALID_QUEUES = ['high', 'default', 'reports'];

    /**
     * Exports que, por diseño, NO deben ser queued (futuros exports ligeros
     * pueden agregarse acá si alguna vez los hay). Hoy: ninguno.
     */
    private const EXPORT_EXCEPTIONS = [];

    /**
     * Jobs de infraestructura del framework que no heredamos nosotros.
     * Hoy vacío — todos los jobs en app/Jobs son nuestros.
     */
    private const JOB_EXCEPTIONS = [];

    public function test_every_export_implements_should_queue(): void
    {
        foreach ($this->discoverClassesIn(app_path('Exports')) as $class) {
            if (in_array($class, self::EXPORT_EXCEPTIONS, true)) {
                continue;
            }

            $this->assertTrue(
                (new ReflectionClass($class))->implementsInterface(ShouldQueue::class),
                "El Export {$class} debe implementar ShouldQueue. Sin eso corre ".
                'síncrono y bloquea el request del usuario al exportar.'
            );
        }
    }

    public function test_every_export_implements_with_chunk_reading(): void
    {
        foreach ($this->discoverClassesIn(app_path('Exports')) as $class) {
            if (in_array($class, self::EXPORT_EXCEPTIONS, true)) {
                continue;
            }

            $this->assertTrue(
                (new ReflectionClass($class))->implementsInterface(WithChunkReading::class),
                "El Export {$class} debe implementar WithChunkReading. Sin ".
                'chunking, 100k filas cargan todo en memoria y tumban el worker.'
            );
        }
    }

    public function test_every_export_routes_to_reports_queue(): void
    {
        foreach ($this->discoverClassesIn(app_path('Exports')) as $class) {
            if (in_array($class, self::EXPORT_EXCEPTIONS, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            $this->assertTrue(
                $reflection->hasProperty('queue'),
                "El Export {$class} debe declarar `public \$queue`. ".
                'Sin esto cae en `default` y bloquea notificaciones.'
            );

            // Instancia sin constructor para leer propiedad pública tipada.
            // Todos nuestros Exports tienen constructores con args opcionales,
            // así que newInstance() funciona — pero newInstanceWithoutConstructor
            // es más robusto frente a firmas futuras.
            $instance = $reflection->newInstanceWithoutConstructor();
            $defaultQueue = $reflection->getProperty('queue')->getValue($instance);

            $this->assertSame(
                'reports',
                $defaultQueue,
                "El Export {$class} debe enrutar a la cola 'reports'. ".
                "Declarado: '{$defaultQueue}'. Ver config/horizon.php."
            );
        }
    }

    public function test_every_job_implements_should_queue(): void
    {
        foreach ($this->discoverClassesIn(app_path('Jobs')) as $class) {
            if (in_array($class, self::JOB_EXCEPTIONS, true)) {
                continue;
            }

            $this->assertTrue(
                (new ReflectionClass($class))->implementsInterface(ShouldQueue::class),
                "El Job {$class} debe implementar ShouldQueue. Jobs síncronos ".
                'no aportan valor sobre un Service class — y bloquean el request.'
            );
        }
    }

    public function test_every_job_declares_a_queue(): void
    {
        foreach ($this->discoverClassesIn(app_path('Jobs')) as $class) {
            if (in_array($class, self::JOB_EXCEPTIONS, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            $this->assertNotNull(
                $constructor,
                "El Job {$class} debe tener constructor con `\$this->onQueue('...')`. ".
                'Sin esto cae en `default` y no podemos garantizar SLA por tipo de job.'
            );

            // Leer el código fuente del constructor y buscar $this->onQueue('xxx').
            // No usamos `public $queue = 'xxx'` porque en PHP 8.3 + Laravel 11 el
            // trait Queueable ya declara $queue sin valor, y declarar el mismo
            // con valor inicial lanza Fatal error por conflicto de traits.
            $source = $this->readMethodSource($reflection, $constructor);

            $this->assertMatchesRegularExpression(
                "/\\\$this->onQueue\\(['\"](\w+)['\"]\\)/",
                $source,
                "El Job {$class} debe llamar `\$this->onQueue('xxx')` en su ".
                'constructor. Colas válidas: '.implode(', ', self::VALID_QUEUES)
            );

            preg_match("/\\\$this->onQueue\\(['\"](\w+)['\"]\\)/", $source, $match);
            $queue = $match[1] ?? null;

            $this->assertContains(
                $queue,
                self::VALID_QUEUES,
                "El Job {$class} enruta a cola '{$queue}' que no existe en ".
                'config/horizon.php. Colas válidas: '.implode(', ', self::VALID_QUEUES)
            );
        }
    }

    public function test_every_job_declares_tries_and_timeout(): void
    {
        // Sin tries/timeout declarados, los jobs heredan los defaults del
        // worker (1 try, sin timeout específico). Eso es trampa: un job que
        // falla por un blip de Redis cae al failed_jobs sin retry, y un job
        // que se cuelga consume el slot del worker indefinidamente.
        // Forzar declaración explícita en CADA job hace la decisión visible.
        foreach ($this->discoverClassesIn(app_path('Jobs')) as $class) {
            if (in_array($class, self::JOB_EXCEPTIONS, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            $this->assertTrue(
                $reflection->hasProperty('tries'),
                "El Job {$class} debe declarar `public int \$tries`. Sin esto ".
                'hereda 1 try y un blip transitorio (Redis intermitente, lock '.
                'momentáneo) condena el job a failed_jobs sin reintento.'
            );

            $this->assertTrue(
                $reflection->hasProperty('timeout'),
                "El Job {$class} debe declarar `public int \$timeout`. Sin esto ".
                'un job colgado puede mantener el slot del worker indefinidamente, '.
                'degradando el throughput de la cola.'
            );

            // Verificar que tries declarado es razonable (>= 1, <= 10).
            $tries = $reflection->getProperty('tries')->getDefaultValue();
            $this->assertGreaterThanOrEqual(1, $tries, "tries del Job {$class} debe ser >= 1");
            $this->assertLessThanOrEqual(10, $tries, "tries del Job {$class} > 10 es spam de retries");

            // Verificar que timeout declarado es razonable (>= 10s, <= 3600s).
            $timeout = $reflection->getProperty('timeout')->getDefaultValue();
            $this->assertGreaterThanOrEqual(10, $timeout, "timeout del Job {$class} debe ser >= 10s");
            $this->assertLessThanOrEqual(3600, $timeout, "timeout del Job {$class} > 3600s es excesivo — partir el job");
        }
    }

    /**
     * Lee el código fuente de un método específico leyendo las líneas del archivo
     * entre getStartLine() y getEndLine(). Útil para inspección estructural cuando
     * la propiedad no se puede leer vía Reflection (ej. se setea en constructor).
     */
    private function readMethodSource(ReflectionClass $class, \ReflectionMethod $method): string
    {
        $file = $class->getFileName();
        if (! $file || ! is_readable($file)) {
            return '';
        }

        $lines = file($file);
        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $method->getStartLine() + 1;

        return implode('', array_slice($lines, $start, $length));
    }

    /**
     * Descubre todas las clases PHP en un directorio y retorna sus FQCN.
     *
     * Usa Finder en vez de get_declared_classes() porque:
     *   - No depende del autoloader (no hace falta haberlas cargado antes)
     *   - Encuentra clases nuevas automáticamente sin actualizar el test
     *
     * @return array<string>
     */
    private function discoverClassesIn(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $classes = [];
        $finder = (new Finder)->files()->in($path)->name('*.php');

        foreach ($finder as $file) {
            $contents = file_get_contents($file->getRealPath());

            if (! preg_match('/namespace\s+([^;]+);/m', $contents, $nsMatch)) {
                continue;
            }
            if (! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $classMatch)) {
                continue;
            }

            $fqcn = trim($nsMatch[1]).'\\'.trim($classMatch[1]);

            if (class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }
}
