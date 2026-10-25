<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\ProductSalesFilters;
use App\Services\Reports\ProductSalesReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * GET /imprimir/reportes/ventas-por-producto?payload={encrypted}
 *
 * Vista de impresión del reporte de ventas por producto. El enlace lo arma
 * ProductSalesReportPage con los filtros que el usuario está viendo.
 *
 * Tres capas: el payload va cifrado (no se arma a mano), el usuario debe
 * tener el permiso del reporte, y las bodegas se vuelven a cruzar contra las
 * suyas — un enlace compartido nunca le muestra a alguien una bodega ajena.
 *
 * Payload: date_from, date_to (Y-m-d), date_field, warehouse_ids, sort.
 */
class ProductSalesReportController extends Controller
{
    public function __invoke(Request $request, ProductSalesReportService $service): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can(ProductSalesReportService::PERMISSION), 403);

        $data = $this->decryptPayload($request);

        try {
            $filters = ProductSalesFilters::fromArray([
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
                'date_field' => $data['date_field'] ?? null,
                'warehouse_ids' => $service->resolveWarehouseIds($user, (array) ($data['warehouse_ids'] ?? [])),
            ]);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $sort = (string) ($data['sort'] ?? ProductSalesReportService::SORT_NET_AMOUNT);
        $report = $service->generateCached($filters);
        $report['rows'] = ProductSalesReportService::sortRows($report['rows'], $sort);

        $html = view('pdf.report-product-sales', [
            'report' => $report,
            'sortLabel' => ProductSalesReportService::sortOptions()[$sort]
                ?? ProductSalesReportService::sortOptions()[ProductSalesReportService::SORT_NET_AMOUNT],
            'supplier' => Supplier::first(),
            'reportNumber' => 'VPP-'.now()->format('Ymd-His'),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptPayload(Request $request): array
    {
        try {
            $data = json_decode(
                Crypt::decryptString((string) $request->query('payload', '')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            abort(403, 'Enlace de reporte inválido o expirado.');
        }

        return is_array($data) ? $data : [];
    }
}
