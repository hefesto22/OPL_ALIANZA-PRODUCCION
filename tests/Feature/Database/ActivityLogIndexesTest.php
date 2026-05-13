<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contrato estructural de índices sobre activity_log.
 *
 * Por qué este test:
 *   Los índices son una optimización invisible — si alguien borra la
 *   migración o renombra los índices, el panel sigue funcionando pero
 *   lentamente. A 30k-50k filas/día sin estos índices, el ActivityLogResource
 *   pasa de 50ms a 5s+ en pocas semanas. El test bloquea cualquier regresión
 *   en migrate:fresh antes de que el bug llegue a producción.
 *
 * Si el negocio decide eliminar uno de estos índices, actualizar este test
 * en el mismo PR para mantenerlo como contrato vivo y no falso amigo.
 */
class ActivityLogIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_log_has_created_at_index(): void
    {
        $this->assertIndexExists(
            'activity_log',
            'activity_log_created_at_index',
            'created_at',
        );
    }

    public function test_activity_log_has_log_name_created_at_composite_index(): void
    {
        $this->assertIndexExists(
            'activity_log',
            'activity_log_log_name_created_at_index',
            'log_name, created_at',
        );
    }

    /**
     * Verifica que existe un índice con el nombre y columnas esperadas usando
     * la vista pg_indexes (Postgres). El test del proyecto golpea Postgres
     * real (BD hozana_test), así que esta introspección funciona directamente.
     */
    private function assertIndexExists(
        string $table,
        string $indexName,
        string $expectedColumnsInOrder,
    ): void {
        $row = DB::selectOne(
            'SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName]
        );

        $this->assertNotNull(
            $row,
            "Falta el índice '{$indexName}' en la tabla '{$table}'. ".
            'Revisar database/migrations/...add_performance_indexes_to_activity_log_table.php'
        );

        // Verificación adicional: que el indexdef contiene las columnas esperadas
        // en el orden esperado. Esto detecta si alguien renombró el índice pero
        // cambió las columnas (caso silencioso particularmente peligroso).
        $this->assertStringContainsString(
            $expectedColumnsInOrder,
            $row->indexdef,
            "El índice '{$indexName}' existe pero NO cubre las columnas esperadas ".
            "({$expectedColumnsInOrder}). Definición actual: {$row->indexdef}"
        );
    }
}
