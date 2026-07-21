<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Huella de duplicado exacto + referencia a la factura original.
     *
     * Contexto (incidente 2026-07): Jaremar re-emite la MISMA factura
     * económica (mismo cliente, mismos productos y cantidades, mismo total)
     * con número fiscal NUEVO y en manifiesto NUEVO, generalmente al día
     * siguiente. La dedupe por invoice_number no puede atraparlas — entraron
     * ~L. 104,000 en papel duplicado en 3 semanas.
     *
     * `fingerprint`: hash md5 canónico de (client_id + líneas ordenadas
     * product_id:fracciones + total). NO incluye fechas, número de factura
     * ni montos por línea: Jaremar recalcula el redondeo por centavos al
     * re-emitir (ej. 2,764.79 vs 2,764.80). Ver App\Support\InvoiceFingerprint.
     *
     * `duplicate_of_invoice_id`: se llena cuando una factura entra como
     * "posible duplicada" (match aislado por debajo del umbral de bloque)
     * apuntando a la factura original idéntica, para revisión humana.
     *
     * Nullable: el backfill (invoices:backfill-fingerprints) llena el
     * histórico; facturas sin client_id o sin líneas quedan null y se
     * excluyen de la detección.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->char('fingerprint', 32)->nullable();
            $table->foreignId('duplicate_of_invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();

            // Lookup del importador: WHERE fingerprint IN (...) AND
            // invoice_date BETWEEN X AND Y — índice compuesto exacto.
            $table->index(['fingerprint', 'invoice_date']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_invoice_id');
            $table->dropIndex(['fingerprint', 'invoice_date']);
            $table->dropColumn('fingerprint');
        });
    }
};
