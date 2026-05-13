<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueo de motor contra race condition TOCTOU en el endpoint
 * POST /api/v1/facturas/insertar.
 *
 * Hoy el controller hace:
 *   1. Consulta payload_hash existente → si no hay, sigue.
 *   2. Crea ApiInvoiceImport con ese payload_hash.
 *
 * Entre (1) y (2) puede haber una segunda llamada concurrente con el mismo
 * hash que también pase el check y cree un segundo registro. Esto es una
 * race ventanal pequeña pero real bajo retry agresivo de Jaremar.
 *
 * Solución: un partial unique index sobre payload_hash que excluye los
 * registros con status='failed'. Los failed deben permitir reintento legítimo
 * (un import falló por bug → al corregir, se reenvía el mismo batch).
 *
 * Sintaxis específica de Postgres (CREATE UNIQUE INDEX ... WHERE ...).
 * Sin equivalente directo en MySQL pero el proyecto corre solo Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX api_invoice_imports_payload_hash_active_unique
            ON api_invoice_imports (payload_hash)
            WHERE status != \'failed\'
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS api_invoice_imports_payload_hash_active_unique');
    }
};
