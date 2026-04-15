<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega índices faltantes a columnas de FK y filtros frecuentes.
 *
 * PostgreSQL NO crea índices automáticamente en columnas de FK
 * (a diferencia de MySQL). Sin estos índices, los JOINs y WHERE
 * por estas columnas hacen sequential scan — O(n) en vez de O(log n).
 *
 * Con miles de registros la diferencia es de segundos vs milisegundos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── FK indexes faltantes ────────────────────────────────────────

        // users.warehouse_id — filtro de WarehouseScope en cada request
        Schema::table('users', function (Blueprint $table) {
            $table->index('warehouse_id', 'idx_users_warehouse_id');
        });

        // routes.warehouse_id — JOIN al listar rutas por bodega
        Schema::table('routes', function (Blueprint $table) {
            $table->index('warehouse_id', 'idx_routes_warehouse_id');
        });

        // manifests — FKs de auditoría y relación con supplier
        Schema::table('manifests', function (Blueprint $table) {
            $table->index('supplier_id', 'idx_manifests_supplier_id');
            $table->index('closed_by', 'idx_manifests_closed_by');
            $table->index('created_by', 'idx_manifests_created_by');
            $table->index('updated_by', 'idx_manifests_updated_by');
        });

        // invoices.warehouse_id — standalone (el composite manifest_id+warehouse_id
        // solo cubre queries que filtran por manifest_id primero)
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('warehouse_id', 'idx_invoices_warehouse_id');
        });

        // returns — FKs sin índice standalone
        Schema::table('returns', function (Blueprint $table) {
            $table->index('invoice_id', 'idx_returns_invoice_id');
            $table->index('return_reason_id', 'idx_returns_return_reason_id');
            $table->index('warehouse_id', 'idx_returns_warehouse_id');
            $table->index('created_by', 'idx_returns_created_by');
            $table->index('reviewed_by', 'idx_returns_reviewed_by');
        });

        // return_lines.invoice_line_id — JOIN frecuente en cálculo de cantidades
        Schema::table('return_lines', function (Blueprint $table) {
            $table->index('invoice_line_id', 'idx_return_lines_invoice_line_id');
        });

        // api_invoice_import_conflicts — FKs de resolución
        Schema::table('api_invoice_import_conflicts', function (Blueprint $table) {
            $table->index('api_invoice_import_id', 'idx_conflicts_import_id');
            $table->index('invoice_id', 'idx_conflicts_invoice_id');
            $table->index('resolved_by', 'idx_conflicts_resolved_by');
        });

        // ── Índices compuestos para queries frecuentes ──────────────────

        // Manifiestos filtrados por status + fecha (dashboard, listados)
        Schema::table('manifests', function (Blueprint $table) {
            $table->index(['status', 'date'], 'idx_manifests_status_date');
        });

        // Facturas filtradas por bodega + fecha (reportes, exports)
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['warehouse_id', 'invoice_date'], 'idx_invoices_warehouse_date');
            $table->index('status', 'idx_invoices_status');
        });

        // Devoluciones filtradas por bodega + fecha (reportes por bodega)
        Schema::table('returns', function (Blueprint $table) {
            $table->index(['warehouse_id', 'return_date'], 'idx_returns_warehouse_return_date');
        });
    }

    public function down(): void
    {
        // ── FK indexes ──────────────────────────────────────────────────
        Schema::table('users', fn (Blueprint $t) => $t->dropIndex('idx_users_warehouse_id'));
        Schema::table('routes', fn (Blueprint $t) => $t->dropIndex('idx_routes_warehouse_id'));

        Schema::table('manifests', function (Blueprint $table) {
            $table->dropIndex('idx_manifests_supplier_id');
            $table->dropIndex('idx_manifests_closed_by');
            $table->dropIndex('idx_manifests_created_by');
            $table->dropIndex('idx_manifests_updated_by');
        });

        Schema::table('invoices', fn (Blueprint $t) => $t->dropIndex('idx_invoices_warehouse_id'));

        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex('idx_returns_invoice_id');
            $table->dropIndex('idx_returns_return_reason_id');
            $table->dropIndex('idx_returns_warehouse_id');
            $table->dropIndex('idx_returns_created_by');
            $table->dropIndex('idx_returns_reviewed_by');
        });

        Schema::table('return_lines', fn (Blueprint $t) => $t->dropIndex('idx_return_lines_invoice_line_id'));

        Schema::table('api_invoice_import_conflicts', function (Blueprint $table) {
            $table->dropIndex('idx_conflicts_import_id');
            $table->dropIndex('idx_conflicts_invoice_id');
            $table->dropIndex('idx_conflicts_resolved_by');
        });

        // ── Índices compuestos ──────────────────────────────────────────
        Schema::table('manifests', fn (Blueprint $t) => $t->dropIndex('idx_manifests_status_date'));

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_warehouse_date');
            $table->dropIndex('idx_invoices_status');
        });

        Schema::table('returns', fn (Blueprint $t) => $t->dropIndex('idx_returns_warehouse_return_date'));
    }
};
