<?php

use App\Http\Controllers\DepositReceiptController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\PrintInvoicesController;
use App\Http\Controllers\PrintReportsController;
use Illuminate\Support\Facades\Route;

// ── Descarga de exportaciones generadas en background ────────────────────
// Triple defensa: `signed` valida que el link fue emitido por nosotros y
// no expiró (TTL 24h, ver NotifyExportReady); `auth` exige sesión activa;
// el controller hace check de path traversal y existencia del archivo.
Route::get('/exports/download', ExportDownloadController::class)
    ->middleware(['web', 'auth', 'signed'])
    ->name('exports.download');

// ── Comprobante de depósito (imagen privada, signed + auth + Policy) ─────
// Cuatro capas: `signed` (TTL 30min — ver Deposit::receipt_url), `auth`
// (sesión activa), DepositPolicy::view en el controller (aislamiento por
// bodega del manifest), y archivo en disco 'local' fuera de public/.
Route::get('/depositos/{deposit}/comprobante', [DepositReceiptController::class, 'show'])
    ->middleware(['web', 'auth', 'signed'])
    ->name('deposits.receipt');

// ── Vista de impresión de facturas ────────────────────────────────────────
// throttle:print-invoices limita a N requests/min por usuario (config/api.php).
// El controller también valida count máximo de facturas por request.
Route::get('/imprimir/facturas', [PrintInvoicesController::class, 'show'])
    ->middleware(['web', 'auth', 'throttle:print-invoices'])
    ->name('invoices.print');

// ── Confirmación de impresión (callback desde JS post-window.afterprint) ──
// Marca las facturas como físicamente impresas. WarehouseScope aísla por
// bodega del usuario autenticado.
Route::post('/imprimir/facturas/confirmar', [PrintInvoicesController::class, 'confirm'])
    ->middleware(['web', 'auth'])
    ->name('invoices.print.confirm');

// ── Reportes — todos protegidos por auth ──────────────────────────────────
Route::prefix('imprimir/reportes')
    ->middleware(['web', 'auth'])
    ->group(function () {

        Route::get('/manifiestos', [PrintReportsController::class, 'manifests'])
            ->name('reports.manifests');

        Route::get('/manifiestos-sin-isv', [PrintReportsController::class, 'manifestsSinIsv'])
            ->name('reports.manifests.sin-isv');

        Route::get('/facturas', [PrintReportsController::class, 'invoices'])
            ->name('reports.invoices');

        Route::get('/devoluciones', [PrintReportsController::class, 'returns'])
            ->name('reports.returns');

        Route::get('/depositos', [PrintReportsController::class, 'deposits'])
            ->name('reports.deposits');

        Route::get('/ventas-por-bodega', [PrintReportsController::class, 'warehouseSales'])
            ->name('reports.warehouse-sales');

        Route::get('/productos', [PrintReportsController::class, 'products'])
            ->name('reports.products');

        Route::get('/facturas-checklist', [PrintReportsController::class, 'invoicesChecklist'])
            ->name('reports.invoices-checklist');
    });
