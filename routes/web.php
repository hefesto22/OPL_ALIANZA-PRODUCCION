<?php

use App\Http\Controllers\DepositReceiptController;
use App\Http\Controllers\PrintInvoicesController;
use App\Http\Controllers\PrintReportsController;
use Illuminate\Support\Facades\Route;

// ── Comprobante de depósito (imagen privada, solo usuarios autenticados) ──
// La imagen se guarda en el disco 'local' (fuera del public/) y solo se
// sirve a través de esta ruta. Cualquier intento de acceso sin sesión activa
// recibe un 403 gracias al middleware 'auth'.
Route::get('/depositos/{deposit}/comprobante', [DepositReceiptController::class, 'show'])
    ->middleware(['web', 'auth'])
    ->name('deposits.receipt');

// ── Vista de impresión de facturas ────────────────────────────────────────
Route::get('/imprimir/facturas', [PrintInvoicesController::class, 'show'])
    ->middleware(['web', 'auth'])
    ->name('invoices.print');

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