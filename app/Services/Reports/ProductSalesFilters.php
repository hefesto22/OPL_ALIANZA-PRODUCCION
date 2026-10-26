<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Filtros ya validados del reporte de ventas por producto.
 *
 * Es inmutable y se arma una sola vez por consulta: la pantalla, la vista
 * de impresión y el Excel reciben el MISMO objeto, así los tres muestran
 * exactamente los mismos números.
 *
 * Las bodegas llegan YA resueltas contra el usuario (ver
 * ProductSalesReportService::resolveWarehouseIds): este objeto nunca decide
 * qué bodegas puede ver alguien, solo transporta la lista.
 */
final class ProductSalesFilters
{
    public const DATE_FIELD_INVOICE = 'invoice_date';

    public const DATE_FIELD_MANIFEST = 'manifest_date';

    /**
     * Rango máximo por consulta: 3 meses. A 10k facturas/día un mes son
     * ~2.4M líneas de factura agregadas en vivo; un año (~30M) ya necesita
     * una tabla de resumen diaria en vez de la consulta directa.
     */
    public const MAX_RANGE_DAYS = 93;

    /**
     * @param  list<int>  $warehouseIds  Nunca vacío: "todas" ya viene expandido a sus IDs.
     */
    private function __construct(
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly string $dateField,
        public readonly array $warehouseIds,
    ) {}

    /**
     * @param  array<string, mixed>  $data  date_from, date_to (Y-m-d), date_field, warehouse_ids
     *
     * @throws InvalidArgumentException con un mensaje listo para mostrar al usuario.
     */
    public static function fromArray(array $data): self
    {
        $dateFrom = self::parseDate($data['date_from'] ?? null, 'la fecha inicial');
        $dateTo = self::parseDate($data['date_to'] ?? null, 'la fecha final');

        if ($dateFrom->greaterThan($dateTo)) {
            throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
        }

        // Días INCLUSIVOS: del 1 al 31 de marzo son 31 días, no 30.
        $days = (int) $dateFrom->diffInDays($dateTo) + 1;

        if ($days > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException(
                'El rango máximo es de 3 meses ('.self::MAX_RANGE_DAYS." días) y elegiste {$days} días. ".
                'Genera el reporte por trimestre.'
            );
        }

        $dateField = (string) ($data['date_field'] ?? self::DATE_FIELD_INVOICE);

        if (! in_array($dateField, [self::DATE_FIELD_INVOICE, self::DATE_FIELD_MANIFEST], true)) {
            throw new InvalidArgumentException('Tipo de fecha no válido.');
        }

        $warehouseIds = array_values(array_unique(array_map(
            'intval',
            is_array($data['warehouse_ids'] ?? null) ? $data['warehouse_ids'] : [],
        )));
        sort($warehouseIds);

        if ($warehouseIds === []) {
            throw new InvalidArgumentException('No hay bodegas para consultar.');
        }

        return new self($dateFrom, $dateTo, $dateField, $warehouseIds);
    }

    /**
     * Forma serializable: viaja cifrada en el enlace de impresión y es la
     * base de la clave de caché.
     *
     * @return array{date_from: string, date_to: string, date_field: string, warehouse_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
            'date_field' => $this->dateField,
            'warehouse_ids' => $this->warehouseIds,
        ];
    }

    /**
     * Restringe una consulta sobre `invoices` (alias `i`) a las facturas del
     * reporte: el período, sin rechazadas ni borradas y, por defecto, solo de
     * las bodegas elegidas.
     *
     * La lista de bodegas va explícita (nunca "sin filtro") para que Postgres
     * use el índice (warehouse_id, invoice_date) también con "todas".
     *
     * @param  bool  $onlySelectedWarehouses  false = de cualquier bodega; lo usa
     *                                        la sección de traslados para las
     *                                        facturas que salieron a otra.
     */
    public function scopeInvoices(Builder $query, bool $onlySelectedWarehouses = true): Builder
    {
        $query->whereNull('i.deleted_at')->where('i.status', '!=', 'rejected');

        if ($onlySelectedWarehouses) {
            $query->whereIn('i.warehouse_id', $this->warehouseIds);
        }

        $range = [$this->dateFrom->toDateString(), $this->dateTo->toDateString()];

        if ($this->dateField === self::DATE_FIELD_MANIFEST) {
            return $query->join('manifests as m', 'm.id', '=', 'i.manifest_id')
                ->whereNull('m.deleted_at')
                ->whereBetween('m.date', $range);
        }

        return $query->whereBetween('i.invoice_date', $range);
    }

    public function cacheKey(): string
    {
        // El "v4" es la forma del reporte guardado: subirlo cada vez que cambien
        // sus llaves, para que tras un deploy nadie reciba de la caché un
        // reporte viejo al que le faltan datos que la vista ya espera.
        return 'reports:product-sales:v4:'.sha1((string) json_encode($this->toArray()));
    }

    public function dateFieldLabel(): string
    {
        return $this->dateField === self::DATE_FIELD_MANIFEST
            ? 'Fecha del manifiesto (día de carga)'
            : 'Fecha de factura';
    }

    private static function parseDate(mixed $value, string $label): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw new InvalidArgumentException("Falta {$label}.");
        }

        $raw = substr($value, 0, 10);

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $raw);
        } catch (\Throwable) {
            $date = null;
        }

        // createFromFormat no falla con un 31 de febrero: lo corre a marzo.
        // Si al reformatear no da lo mismo, la fecha no existe.
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException("Revisa {$label}: no es una fecha válida.");
        }

        return $date->startOfDay();
    }
}
