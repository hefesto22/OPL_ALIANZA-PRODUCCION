<?php

namespace Tests\Unit\Architecture;

use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Test arquitectural — contrato de aislamiento multi-tenant.
 *
 * ¿Qué protege este test?
 *
 * El proyecto tiene dos tipos de usuarios:
 *   - Globales (warehouse_id = null): admin, super_admin, haremar — ven TODO.
 *   - De bodega (warehouse_id = X): encargado, operador — ven SOLO su bodega.
 *
 * El aislamiento se aplica explícitamente vía `App\Support\WarehouseScope` (no
 * hay Global Scope automático). Si mañana alguien crea un nuevo Resource o
 * Widget y olvida aplicar el filtro, un encargado de la bodega OAC podría
 * ver datos de OAS u OAO — violación directa de la política de aislamiento.
 *
 * Este test recorre estructuralmente todos los Resources y Widgets de Filament
 * y verifica que cada uno aplique alguno de los patrones sanctioned:
 *
 *   1. `WarehouseScope::apply(` — filtra por columna warehouse_id directa
 *   2. `WarehouseScope::applyViaRelation(` — filtra por relación (manifest, invoices)
 *   3. `WarehouseScope::cacheKey(` — cache aislado por bodega (implica filtro interno)
 *   4. `WarehouseScope::getWarehouseId(` — uso directo del helper
 *   5. `isWarehouseUser()` / `isGlobalUser()` — check manual en la clase
 *   6. `canView()` — restringe el widget a usuarios globales
 *   7. `Resource::getEloquentQuery()` — delega al método de un Resource ya validado
 *
 * Si ninguno de esos patrones aparece en el código de la clase, el test falla
 * con un mensaje claro indicando qué hay que agregar.
 *
 * Mantener las listas de excepciones cortas y bien justificadas — cada excepción
 * es un agujero que puede convertirse en fuga si no se audita periódicamente.
 */
class MultiTenantContractTest extends TestCase
{
    /**
     * Resources cuyo modelo es global por diseño — NO deben filtrar por bodega.
     *
     *   - UserResource: gestión de usuarios (super_admin puede ver todos; los
     *     filtros de jerarquía viven en User::scopeVisibleTo, no en warehouse).
     *   - ActivityLogResource: bitácora global del sistema.
     *   - WarehouseResource: catálogo de bodegas (auto-referencial).
     *   - ReturnReasonResource: catálogo de razones, compartido entre bodegas.
     *
     * Cada entrada debe justificarse en comentario. No agregar sin revisión.
     */
    private const GLOBAL_RESOURCES = [
        \App\Filament\Resources\Users\UserResource::class,
        \App\Filament\Resources\ActivityLogResource::class,
        \App\Filament\Resources\Catalogs\WarehouseResource::class,
        \App\Filament\Resources\Catalogs\ReturnReasonResource::class,
    ];

    /**
     * Widgets exentos del check — hoy ninguno. Si algún widget futuro es
     * intencionalmente global (p.ej. métricas del sistema para super_admin),
     * agregarlo aquí con justificación.
     */
    private const GLOBAL_WIDGETS = [];

    /**
     * Regex que detecta cualquiera de los patrones sanctioned de filtrado.
     *
     * El orden no importa — solo se necesita que UNO de los patrones aparezca
     * en el código fuente de la clase para que pase.
     */
    private const SANCTIONED_PATTERNS_REGEX =
        '/WarehouseScope::(apply|applyViaRelation|cacheKey|getWarehouseId)'.
        '|isWarehouseUser\(\)'.
        '|isGlobalUser\(\)'.
        '|function\s+canView'.
        '|Resource::getEloquentQuery\(\)/';

    public function test_every_filament_resource_applies_warehouse_filter(): void
    {
        $resources = $this->discoverClassesIn(
            app_path('Filament/Resources'),
            requireSuffix: 'Resource'
        );

        $this->assertNotEmpty(
            $resources,
            'No se descubrió ningún Resource de Filament. Revisá la ruta.'
        );

        foreach ($resources as $class) {
            if (in_array($class, self::GLOBAL_RESOURCES, true)) {
                continue;
            }

            $source = $this->readClassSource($class);

            $this->assertMatchesRegularExpression(
                self::SANCTIONED_PATTERNS_REGEX,
                $source,
                "El Resource {$class} no aplica ningún patrón de filtrado ".
                'multi-tenant. Agregá `WarehouseScope::apply($query)` o '.
                "`WarehouseScope::applyViaRelation(\$query, 'relacion')` en ".
                'su método `getEloquentQuery()`. Si el modelo es global por '.
                'diseño, sumá la clase a GLOBAL_RESOURCES con justificación.'
            );
        }
    }

    public function test_every_filament_widget_applies_warehouse_filter_or_is_admin_only(): void
    {
        $widgets = $this->discoverClassesIn(app_path('Filament/Widgets'));

        $this->assertNotEmpty(
            $widgets,
            'No se descubrió ningún Widget de Filament. Revisá la ruta.'
        );

        foreach ($widgets as $class) {
            if (in_array($class, self::GLOBAL_WIDGETS, true)) {
                continue;
            }

            $source = $this->readClassSource($class);

            $this->assertMatchesRegularExpression(
                self::SANCTIONED_PATTERNS_REGEX,
                $source,
                "El Widget {$class} no aplica ningún patrón de filtrado ".
                'multi-tenant. Opciones válidas:'."\n".
                '  • Filtrá queries con `WarehouseScope::apply($q)` o '.
                "`applyViaRelation(\$q, 'relacion')`.\n".
                "  • Usá `Cache::remember(WarehouseScope::cacheKey('...'), ...)` ".
                "para aislar caché por bodega.\n".
                '  • Si el widget compara bodegas entre sí (solo útil para '.
                'globales), agregá `public static function canView(): bool { '.
                "return Auth::user()?->isGlobalUser() ?? false; }`.\n".
                '  • Si reusa un Resource ya filtrado, invocá '.
                '`SomeResource::getEloquentQuery()` (patrón de LatestManifestsWidget).'
            );
        }
    }

    /**
     * Lee el código fuente completo de una clase.
     *
     * Usamos el file del ReflectionClass porque es O(1) y no depende de que
     * la clase esté cargada vía autoloader (Finder la descubre directamente
     * del filesystem).
     */
    private function readClassSource(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        return ($file && is_readable($file)) ? file_get_contents($file) : '';
    }

    /**
     * Descubre todas las clases PHP bajo un directorio y retorna sus FQCN.
     *
     * Usa Finder en vez de get_declared_classes() para:
     *   - No depender del autoloader (Filament puede cargar clases on-demand)
     *   - Incluir clases nuevas automáticamente sin tocar este test
     *
     * @param  string|null  $requireSuffix  si se pasa, filtra solo clases cuyo
     *                                      nombre termine en ese sufijo (ej. "Resource"
     *                                      para evitar traerse Pages, Schemas, Tables
     *                                      que viven en subcarpetas de cada Resource).
     * @return array<string>
     */
    private function discoverClassesIn(string $path, ?string $requireSuffix = null): array
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

            $className = trim($classMatch[1]);

            if ($requireSuffix !== null && ! str_ends_with($className, $requireSuffix)) {
                continue;
            }

            $fqcn = trim($nsMatch[1]).'\\'.$className;

            if (class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }
}
