<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Contrato de la tabla de facturas del manifiesto (InvoicesRelationManager).
 *
 * Decisiones operativas pedidas por la usuaria (2026-07-03) que no deben
 * revertirse por accidente en refactors:
 *
 *  1. El filtro Ruta es multi-selección: la operación filtra 1 o más rutas,
 *     usa "Selecciona todos" (que respeta filtros) e imprime de un solo tiro.
 *  2. "Imprimir Facturas" (formato Jaremar) es solo super_admin por ahora.
 *  3. Ninguna acción masiva deselecciona al terminar: la misma selección se
 *     reutiliza para imprimir facturas + sublistas sin volver a marcar todo.
 *
 * Se valida sobre el código fuente (patrón de contract-tests del proyecto)
 * porque la suite no usa tests Livewire para configuración de tablas.
 */
class InvoicesRelationManagerContractTest extends TestCase
{
    private function source(): string
    {
        // Ruta relativa al repo (tests/Unit/Architecture → raíz), sin app_path():
        // este test extiende el TestCase puro de PHPUnit y no bootea Laravel.
        return file_get_contents(
            dirname(__DIR__, 3).'/app/Filament/Resources/Manifests/RelationManagers/InvoicesRelationManager.php'
        );
    }

    public function test_route_filter_is_multiple(): void
    {
        $source = $this->source();

        // El filtro de ruta debe permitir seleccionar varias rutas a la vez.
        $this->assertMatchesRegularExpression(
            "/SelectFilter::make\('route_number'\)[\\s\\S]{0,400}->multiple\(\)/",
            $source,
            'El filtro Ruta debe ser ->multiple(): la operación imprime varias rutas de una sola selección.'
        );
    }

    public function test_imprimir_facturas_is_super_admin_only(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            "/BulkAction::make\('imprimir_seleccionadas'\)[\\s\\S]{0,600}hasRole\('super_admin'\)/",
            $source,
            'La acción "Imprimir Facturas" debe estar visible solo para super_admin (decisión 2026-07-03).'
        );
    }

    public function test_bulk_actions_keep_selection_after_completion(): void
    {
        $source = $this->source();

        // Se busca la LLAMADA al método (->deselect...), no la palabra suelta:
        // el archivo documenta la decisión en un comentario que la menciona.
        $this->assertStringNotContainsString(
            '->deselectRecordsAfterCompletion(',
            $source,
            'Las acciones masivas NO deben deseleccionar al terminar: la operación reutiliza la misma selección para varios documentos.'
        );
    }
}
