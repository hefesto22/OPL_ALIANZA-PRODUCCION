<?php

namespace Tests\Unit\Support;

use App\Support\DatabaseErrorTranslator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests unitarios puros (sin bootstrap de Laravel) del traductor de errores de
 * base de datos que usa el 500 de POST /api/v1/facturas/insertar.
 *
 * Los dos mensajes largos que se usan acá son los REALES de los incidentes de
 * septiembre 2026, copiados de `api_invoice_imports.failure_message`. No son
 * inventados: si mañana cambia el formato de PDO o de Laravel, estos tests
 * fallan y avisan que el parseo quedó obsoleto.
 *
 * Lo que se protege:
 *   1. Que cada SQLSTATE conocido produzca su motivo estable.
 *   2. Que el detalle traiga el valor y el tipo destino — lo único accionable.
 *   3. Que NUNCA se filtre host, puerto, nombre de base ni el SQL. Esta
 *      respuesta va a un tercero.
 */
class DatabaseErrorTranslatorTest extends TestCase
{
    /** Error real del 2026-09-10: InvoiceId de SAP contra un integer. */
    private const ERROR_FUERA_DE_RANGO = 'SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  value "9000000105" is out of range for type integer'
        ."\n".'CONTEXT:  unnamed portal parameter $7 = \'...\' (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database: hozana_pruebas, SQL: insert into "invoice_lines" ("conversion_factor", "cost", "invoice_jaremar_id") values (1, 0, 9000000105))';

    /** Error real del 2026-09-08: NumeroManifiesto de 24 chars contra varchar(20). */
    private const ERROR_DEMASIADO_LARGO = 'SQLSTATE[22001]: String data, right truncated: 7 ERROR:  value too long for type character varying(20) (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database: hozana_pruebas, SQL: insert into "manifests" ("supplier_id", "number", "date") values (1, 1234-56-78T00:00:00.000Z, 2026-09-08 00:00:00))';

    public function test_valor_fuera_de_rango_devuelve_motivo_y_valor(): void
    {
        $r = DatabaseErrorTranslator::translate(new RuntimeException(self::ERROR_FUERA_DE_RANGO));

        $this->assertSame('DATO_FUERA_DE_RANGO', $r['motivo']);
        $this->assertSame('las líneas de la factura', $r['ubicacion']);
        $this->assertSame('value "9000000105" is out of range for type integer', $r['detalle']);
        $this->assertStringContainsString('9000000105', $r['mensaje']);
    }

    public function test_valor_demasiado_largo_devuelve_motivo_y_tipo(): void
    {
        $r = DatabaseErrorTranslator::translate(new RuntimeException(self::ERROR_DEMASIADO_LARGO));

        $this->assertSame('DATO_DEMASIADO_LARGO', $r['motivo']);
        $this->assertSame('el encabezado del manifiesto', $r['ubicacion']);
        $this->assertSame('value too long for type character varying(20)', $r['detalle']);
    }

    public function test_el_contexto_de_postgres_no_entra_en_el_detalle(): void
    {
        // PostgreSQL agrega "CONTEXT: unnamed portal parameter $7 = '...'"
        // después del ERROR. No orienta a nadie y ensucia la respuesta.
        $r = DatabaseErrorTranslator::translate(new RuntimeException(self::ERROR_FUERA_DE_RANGO));

        $this->assertStringNotContainsString('CONTEXT', (string) $r['detalle']);
        $this->assertStringNotContainsString('portal parameter', (string) $r['detalle']);
    }

    /**
     * El guard que más importa: esta respuesta viaja a un tercero.
     */
    public function test_nunca_filtra_infraestructura_ni_el_sql(): void
    {
        foreach ([self::ERROR_FUERA_DE_RANGO, self::ERROR_DEMASIADO_LARGO] as $raw) {
            $r = DatabaseErrorTranslator::translate(new RuntimeException($raw));
            $expuesto = $r['mensaje'].' '.((string) $r['detalle']);

            foreach (['Host', '127.0.0.1', 'Port', '5432', 'Database', 'hozana_pruebas', 'insert into', 'Connection'] as $prohibido) {
                $this->assertStringNotContainsString(
                    $prohibido,
                    $expuesto,
                    "El cuerpo de la respuesta filtró '{$prohibido}'."
                );
            }
        }
    }

    public function test_excepcion_desconocida_cae_al_mensaje_generico(): void
    {
        // Sin SQLSTATE reconocible no hay forma de saber qué trae el mensaje,
        // así que no se expone NADA de él.
        $r = DatabaseErrorTranslator::translate(
            new RuntimeException('Undefined property $foo on line 42 of /var/www/app/Secreto.php')
        );

        $this->assertSame('ERROR_INTERNO', $r['motivo']);
        $this->assertNull($r['detalle']);
        $this->assertNull($r['ubicacion']);
        $this->assertStringNotContainsString('Secreto', $r['mensaje']);
        $this->assertStringContainsString('Error interno al procesar las facturas', $r['mensaje']);
    }

    public function test_sqlstate_no_mapeado_cae_al_mensaje_generico(): void
    {
        $r = DatabaseErrorTranslator::translate(
            new RuntimeException('SQLSTATE[08006]: Connection failure: could not connect (Connection: pgsql, Host: 10.0.0.9)')
        );

        $this->assertSame('ERROR_INTERNO', $r['motivo']);
        $this->assertNull($r['detalle']);
        $this->assertStringNotContainsString('10.0.0.9', $r['mensaje']);
    }

    public function test_duplicado_y_campo_obligatorio_tienen_su_propio_motivo(): void
    {
        $dup = DatabaseErrorTranslator::translate(new RuntimeException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "invoices_invoice_number_unique" (Connection: pgsql, SQL: insert into "invoices" ("x") values (1))'
        ));
        $this->assertSame('REGISTRO_DUPLICADO', $dup['motivo']);
        $this->assertSame('la factura', $dup['ubicacion']);

        $nulo = DatabaseErrorTranslator::translate(new RuntimeException(
            'SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "total" violates not-null constraint (Connection: pgsql, SQL: insert into "invoice_lines" ("x") values (1))'
        ));
        $this->assertSame('CAMPO_OBLIGATORIO_VACIO', $nulo['motivo']);
    }

    public function test_tabla_desconocida_deja_la_ubicacion_nula_sin_romper(): void
    {
        $r = DatabaseErrorTranslator::translate(new RuntimeException(
            'SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  value "999" is out of range for type integer (Connection: pgsql, SQL: insert into "tabla_que_no_mapeamos" ("x") values (1))'
        ));

        $this->assertSame('DATO_FUERA_DE_RANGO', $r['motivo']);
        $this->assertNull($r['ubicacion']);
        $this->assertSame('value "999" is out of range for type integer', $r['detalle']);
    }
}
