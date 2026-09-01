<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribuye cada depósito a una bodega.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *  `deposits` solo sabía de qué MANIFIESTO era la plata, no de qué bodega.
 *  En un manifiesto de una sola bodega da igual, pero desde que existen
 *  manifiestos multi-bodega (traslado de facturas entre bodegas, 27/08) el
 *  encargado de Santa Bárbara veía como suyo el saldo del manifiesto entero.
 *
 *  Facturas y devoluciones YA se saben repartir: `manifest_warehouse_totals`
 *  guarda total_invoices y total_returns por bodega. Las columnas
 *  total_deposited y difference de esa misma tabla existen desde el inicio
 *  pero SIEMPRE quedaron en cero, porque no había forma de saber a qué
 *  bodega imputar un depósito. Esta columna es lo que faltaba.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ UNA COLUMNA Y NO DEDUCIRLO DE created_by
 * ─────────────────────────────────────────────────────────────────────
 *  Se evaluó inferir la bodega del usuario que registró el depósito. Se
 *  descartó: la relación usuario-bodega es mutable (pivote user_warehouse).
 *  Si a un encargado lo mueven de bodega, TODOS sus depósitos históricos
 *  cambiarían de dueño retroactivamente y los reportes del pasado dejarían
 *  de cuadrar. Un dato contable se congela al momento del hecho.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ NULLABLE
 * ─────────────────────────────────────────────────────────────────────
 *  Los depósitos históricos no tienen la atribución. El comando
 *  `deposits:backfill-warehouse` la deduce de created_by donde es
 *  inequívoco (usuario de exactamente una bodega); lo que quede ambiguo
 *  —usuario global o multi-bodega— se deja NULL a propósito y se resuelve
 *  a mano. Inventar una bodega para que la columna sea NOT NULL sería
 *  meter un dato falso en un reporte financiero.
 *
 *  restrictOnDelete: una bodega con depósitos imputados no se borra en
 *  silencio, igual que en manifest_warehouse_totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('manifest_id')
                ->constrained()
                ->restrictOnDelete();

            // El recálculo por bodega agrupa por (manifest_id, warehouse_id)
            // en cada depósito, devolución y cierre. A 10k facturas/día ese
            // recálculo corre miles de veces por jornada.
            $table->index(['manifest_id', 'warehouse_id'], 'deposits_manifest_warehouse_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropIndex('deposits_manifest_warehouse_idx');
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
