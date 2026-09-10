<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `invoice_lines.invoice_jaremar_id` pasa de integer a string.
     *
     * Incidente 2026-09-10 en pruebas: SAP mandó el InvoiceId "9000000105" y
     * el lote murió con SQLSTATE 22003 — "value 9000000105 is out of range for
     * type integer". El integer de Postgres llega a 2,147,483,647 y los IDs
     * nuevos de SAP arrancan en 9,000,000,xxx, más de cuatro veces ese techo.
     * Jaremar solo veía el 500 genérico "Error interno al procesar las
     * facturas" (batch 0b81b38d-f028-4617-b724-d7e40bbe233f).
     *
     * Se elige string y no bigInteger porque los otros TRES identificadores
     * que llegan del mismo origen ya son string — invoices.jaremar_id,
     * invoice_lines.jaremar_line_id y returns.jaremar_return_id. Este campo
     * era la única excepción numérica, y guardarlo igual que sus hermanos
     * evita tener que castear para compararlos y aguanta un ID alfanumérico
     * si SAP algún día manda uno.
     *
     * Se usa DB::statement con USING explícito en vez de ->change(): Postgres
     * NO convierte integer a varchar automáticamente y aborta pidiendo el
     * USING. El campo es informativo (el vínculo real es invoice_id) y no
     * participa en ninguna query, join ni índice, así que el cambio de tipo
     * no toca ninguna consulta existente.
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE invoice_lines
                ALTER COLUMN invoice_jaremar_id TYPE VARCHAR(255)
                USING invoice_jaremar_id::VARCHAR'
        );
    }

    /**
     * El rollback vuelve a integer y FALLA si para entonces ya entró algún ID
     * fuera de rango — que es exactamente el dato que esta migración vino a
     * permitir. Es el comportamiento correcto: revertir tiene que avisar que
     * hay filas que el tipo viejo no puede representar, no truncarlas.
     */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE invoice_lines
                ALTER COLUMN invoice_jaremar_id TYPE INTEGER
                USING invoice_jaremar_id::INTEGER'
        );
    }
};
