<?php

namespace App\Imports\Edt;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Lee UNA hoja del Excel de precios del cliente ("Pedido EDTH" por defecto)
 * tal cual viene: filas crudas, sin interpretar encabezados. La
 * interpretación (qué columna es cuál, validación, duplicados) la hace
 * EdtProductImportService, que no depende de Excel y se prueba con arrays.
 *
 * No usa WithHeadingRow a propósito: Maatwebsite convierte "PVD - ISV" y
 * "PVD + ISV" al mismo nombre ("pvd_isv") y una columna pisaría a la otra.
 */
class EdtPriceListImport implements ToArray, WithMultipleSheets
{
    /** @var list<array<int, mixed>> */
    public array $rows = [];

    public function __construct(private readonly string $sheetName) {}

    public function sheets(): array
    {
        return [$this->sheetName => $this];
    }

    /**
     * @param  array<int, array<int, mixed>>  $array
     */
    public function array(array $array): void
    {
        $this->rows = array_values($array);
    }
}
