<?php

namespace App\Services\Edt;

use App\Casts\Uppercase;
use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Carga del catálogo del EDT desde el Excel de precios del cliente
 * ("Calculo Precios - EDT Honduras.xlsx", hoja "Pedido EDTH").
 *
 * Tres pasos, separados para poder mostrar el plan antes de guardar:
 *
 *   1. parse(): filas crudas → filas validadas. Ubica los encabezados por
 *      nombre (no por posición) y solo usa columnas de ENTRADA; las de
 *      fórmula (PVD + ISV, PC, mayoristas…) se ignoran: las calcula el
 *      sistema. Valida TODO lo que la BD exigiría (largos, ISV, decimales,
 *      topes), para que lo que dice el modo prueba sea lo que pasa de verdad.
 *      Una celda con fórmula en una columna de entrada es error: Maatwebsite
 *      la entrega como texto ("=F2/1.15"), no como el resultado.
 *   2. plan(): compara con lo que ya hay en la BD, fila por fila y en orden.
 *      Un código repetido en el Excel es el MISMO producto: la fila de abajo
 *      es una actualización, y si cambió el precio va al historial
 *      (decisión de Mauricio, 2026-10-03). Para un producto que ya existía,
 *      solo cuenta la ÚLTIMA fila de su código, así que correr el mismo
 *      archivo dos veces no cambia nada la segunda vez.
 *   3. apply(): ejecuta el plan en UNA transacción. Si algo falla no queda
 *      nada a medias.
 */
class EdtProductImportService
{
    /**
     * Encabezado normalizado del Excel → campo del sistema.
     */
    private const HEADERS = [
        'cod. articulo' => 'code',
        'descripcion' => 'description',
        'categoria' => 'category',
        'familia' => 'family',
        'presentacion' => 'presentation',
        'isv' => 'isv',
        'uxc' => 'units_per_box',
        'pvd - isv' => 'list_price',
    ];

    private const REQUIRED = ['code', 'isv', 'units_per_box', 'list_price'];

    /** Encabezado tal como se ve en el Excel, para los mensajes. */
    private const LABELS = [
        'code' => 'Cod. Articulo',
        'description' => 'Descripción',
        'category' => 'Categoria',
        'family' => 'Familia',
        'presentation' => 'Presentacion',
        'isv' => 'ISV',
        'units_per_box' => 'UxC',
        'list_price' => 'PVD - ISV',
    ];

    /** Largo máximo de cada texto (columnas de edt_products). */
    private const MAX_LENGTH = [
        'code' => 30,
        'description' => 200,
        'category' => 60,
        'family' => 60,
        'presentation' => 60,
    ];

    /** Tope de numeric(12,4) del precio de lista. */
    private const MAX_LIST_PRICE = '99999999.9999';

    /**
     * @param  list<array<int, mixed>>  $rows  filas crudas de la hoja
     * @return array{rows: list<array{row: int, code: string, description: string|null, category: string|null, family: string|null, presentation: string|null, isv_pct: string, units_per_box: int, list_price: string}>, errors: list<string>}
     */
    public function parse(array $rows): array
    {
        [$headerIndex, $columns] = $this->locateHeader($rows);

        if ($headerIndex === null) {
            return ['rows' => [], 'errors' => ['No se encontró la fila de encabezados (se busca la columna "Cod. Articulo").']];
        }

        $missing = array_diff(self::REQUIRED, array_keys($columns));
        if ($missing !== []) {
            return ['rows' => [], 'errors' => ['Faltan columnas en el Excel: '.implode(', ', $missing).'.']];
        }

        $parsed = [];
        $errors = [];

        foreach (array_slice($rows, $headerIndex + 1, preserve_keys: true) as $index => $raw) {
            $excelRow = $index + 1; // fila tal como se ve en Excel
            $value = fn (string $field): mixed => isset($columns[$field]) ? ($raw[$columns[$field]] ?? null) : null;

            $rawCode = $value('code');
            if ($rawCode === null || (is_string($rawCode) && trim($rawCode) === '')) {
                continue; // fila vacía
            }

            try {
                foreach (array_keys(self::LABELS) as $field) {
                    $this->rejectFormula($value($field), $field);
                }

                $code = (string) $this->code($rawCode);

                $parsed[] = [
                    'row' => $excelRow,
                    'code' => $this->limited('code', $code),
                    'description' => $this->limited('description', Uppercase::normalize($this->text($value('description')))),
                    'category' => $this->limited('category', Uppercase::normalize($this->text($value('category')))),
                    'family' => $this->limited('family', Uppercase::normalize($this->text($value('family')))),
                    'presentation' => $this->limited('presentation', Uppercase::normalize($this->text($value('presentation')))),
                    'isv_pct' => $this->isvPct($value('isv')),
                    'units_per_box' => $this->unitsPerBox($value('units_per_box')),
                    'list_price' => $this->listPrice($value('list_price')),
                ];
            } catch (Throwable $e) {
                $label = is_scalar($rawCode) ? (string) $rawCode : '?';
                $errors[] = "Fila {$excelRow} (código {$label}): {$e->getMessage()}";
            }
        }

        return ['rows' => $parsed, 'errors' => $errors];
    }

    /**
     * @param  list<array{row: int, code: string, description: string|null, category: string|null, family: string|null, presentation: string|null, isv_pct: string, units_per_box: int, list_price: string}>  $rows
     * @return list<array{action: string, row: int, code: string, attributes: array<string, mixed>, before: array<string, mixed>|null, price_changed: bool, error: string|null}>
     */
    public function plan(array $rows, EdtSupplier $supplier): array
    {
        // Estado vigente por código: primero lo que hay en la BD, después lo
        // que van dejando las filas anteriores del mismo Excel.
        $state = EdtProduct::query()
            ->whereIn('code', array_unique(array_column($rows, 'code')))
            ->get()
            ->mapWithKeys(fn (EdtProduct $product): array => [$product->code => [
                'supplier_id' => $product->supplier_id,
                'description' => $product->description,
                'category' => $product->category,
                'family' => $product->family,
                'presentation' => $product->presentation,
                'isv_pct' => (string) $product->isv_pct,
                'units_per_box' => $product->units_per_box,
                'list_price' => (string) $product->list_price,
            ]])
            ->all();

        // Si el producto YA existía antes de esta carga, solo cuenta la última
        // fila de su código: las de arriba son precios viejos que ya pasaron
        // (y que ya están en el historial si se cargaron antes). Sin esto, volver
        // a correr el mismo Excel "regresaría" al precio viejo y luego al nuevo.
        $existedBefore = array_fill_keys(array_keys($state), true);
        $lastRowByCode = [];
        foreach ($rows as $row) {
            $lastRowByCode[$row['code']] = $row['row'];
        }

        $plan = [];

        foreach ($rows as $row) {
            if (isset($existedBefore[$row['code']]) && $lastRowByCode[$row['code']] !== $row['row']) {
                $plan[] = $this->entry('unchanged', $row);

                continue;
            }

            $current = $state[$row['code']] ?? null;

            if ($current === null) {
                if ($row['description'] === null) {
                    $plan[] = $this->entry('error', $row, error: 'producto nuevo sin descripción');

                    continue;
                }

                $attributes = ['supplier_id' => $supplier->id] + $this->rowAttributes($row, []);
                $plan[] = $this->entry('create', $row, $attributes);
                $state[$row['code']] = $attributes;

                continue;
            }

            if ((int) $current['supplier_id'] !== $supplier->id) {
                $plan[] = $this->entry('error', $row, error: 'el código ya existe con otro proveedor');

                continue;
            }

            $attributes = $this->rowAttributes($row, $current);
            $priceChanged = $this->priceChanged($current, $attributes);
            $otherChanged = $this->descriptiveChanged($current, $attributes);

            $plan[] = ($priceChanged || $otherChanged)
                ? $this->entry('update', $row, $attributes, before: $current, priceChanged: $priceChanged)
                : $this->entry('unchanged', $row, $attributes, before: $current);

            $state[$row['code']] = ['supplier_id' => $supplier->id] + $attributes;
        }

        return $plan;
    }

    /**
     * Ejecuta el plan en una sola transacción.
     *
     * @param  list<array{action: string, row: int, code: string, attributes: array<string, mixed>, before: array<string, mixed>|null, price_changed: bool, error: string|null}>  $plan
     */
    public function apply(array $plan, string $fileName): void
    {
        DB::transaction(function () use ($plan, $fileName): void {
            foreach ($plan as $entry) {
                $note = "Excel {$fileName}, fila {$entry['row']}";

                if ($entry['action'] === 'create') {
                    (new EdtProduct($entry['attributes']))
                        ->withPriceChangeReason(PriceChangeReason::CargaInicial, $note)
                        ->save();

                    continue;
                }

                if ($entry['action'] === 'update') {
                    /** @var EdtProduct $product */
                    $product = EdtProduct::query()->where('code', $entry['code'])->lockForUpdate()->firstOrFail();

                    // El plan se armó antes de confirmar. Si mientras tanto
                    // alguien cambió el producto en la pantalla, no se pisa:
                    // se cancela TODA la carga (la transacción se revierte).
                    if ($entry['before'] !== null && $this->changedSincePlan($entry['before'], $product)) {
                        throw new RuntimeException(
                            "El producto {$entry['code']} cambió mientras se confirmaba la carga. ".
                            'No se guardó nada; vuelve a correr el comando.'
                        );
                    }

                    $product->fill($entry['attributes'])
                        ->withPriceChangeReason(PriceChangeReason::CargaInicial, $note)
                        ->save();
                }
            }
        });
    }

    /**
     * @return array{0: int|null, 1: array<string, int>}
     */
    private function locateHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 15, preserve_keys: true) as $index => $row) {
            $columns = [];

            foreach ($row as $column => $cell) {
                $field = self::HEADERS[$this->normalizeHeader($cell)] ?? null;

                // Si un encabezado se repite, vale el primero (Excel de izquierda a derecha).
                if ($field !== null && ! isset($columns[$field])) {
                    $columns[$field] = $column;
                }
            }

            if (isset($columns['code'])) {
                return [$index, $columns];
            }
        }

        return [null, []];
    }

    private function normalizeHeader(mixed $cell): string
    {
        if (! is_string($cell)) {
            return '';
        }

        return (string) Str::of($cell)->ascii()->lower()->squish();
    }

    /**
     * 27000207 llega como número; se guarda como texto sin ".0".
     */
    private function code(mixed $value): ?string
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return Uppercase::normalize($value === null ? null : (string) $value);
    }

    private function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function rejectFormula(mixed $value, string $field): void
    {
        if (is_string($value) && str_starts_with(ltrim($value), '=')) {
            throw new InvalidArgumentException(
                'la columna "'.self::LABELS[$field].'" tiene una fórmula; cópiala y pégala como valores'
            );
        }
    }

    private function limited(string $field, ?string $value): ?string
    {
        if ($value !== null && mb_strlen($value) > self::MAX_LENGTH[$field]) {
            throw new InvalidArgumentException(
                '"'.self::LABELS[$field].'" pasa de '.self::MAX_LENGTH[$field].' caracteres'
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function changedSincePlan(array $before, EdtProduct $product): bool
    {
        $current = [
            'description' => $product->description,
            'category' => $product->category,
            'family' => $product->family,
            'presentation' => $product->presentation,
            'isv_pct' => (string) $product->isv_pct,
            'units_per_box' => $product->units_per_box,
            'list_price' => (string) $product->list_price,
        ];

        return $this->priceChanged($before, $current) || $this->descriptiveChanged($before, $current);
    }

    /**
     * El Excel guarda el ISV como fracción (0.15); se aceptan también 15 o 18.
     */
    private function isvPct(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('ISV vacío o no numérico');
        }

        $pct = BigDecimal::of((string) $value);
        if ($pct->isLessThanOrEqualTo(1)) {
            $pct = $pct->multipliedBy(100);
        }

        $pct = $pct->toScale(2, RoundingMode::HalfUp);

        if (! in_array((string) $pct, ['0.00', '15.00', '18.00'], true)) {
            throw new InvalidArgumentException("ISV {$value} no es 0, 15% ni 18%");
        }

        return (string) $pct;
    }

    private function unitsPerBox(mixed $value): int
    {
        if (! is_numeric($value) || (float) $value < 1 || (float) $value > 100000 || floor((float) $value) !== (float) $value) {
            throw new InvalidArgumentException('unidades por caja (UxC) inválidas: debe ser un entero de 1 a 100,000');
        }

        return (int) $value;
    }

    /**
     * El precio de lista va con máximo 4 decimales (lo que guarda la BD). Si
     * trae más, es error y no se redondea en silencio: un decimal de más
     * puede mover el precio mayorista un lempira respecto al Excel.
     */
    private function listPrice(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('precio de lista (PVD - ISV) vacío o no numérico');
        }

        $price = BigDecimal::of((string) $value);

        if ($price->getScale() > 4 && ! $price->isEqualTo($price->toScale(4, RoundingMode::Down))) {
            throw new InvalidArgumentException("precio de lista {$value} tiene más de 4 decimales; redondéalo a 4");
        }

        if (! $price->isGreaterThan(0)) {
            throw new InvalidArgumentException('precio de lista (PVD - ISV) en cero o negativo');
        }

        if ($price->isGreaterThan(self::MAX_LIST_PRICE)) {
            throw new InvalidArgumentException('precio de lista (PVD - ISV) demasiado grande');
        }

        return (string) $price->toScale(4);
    }

    /**
     * Lo que trae la fila; si una celda descriptiva viene vacía (pasa en el
     * Excel con códigos repetidos), se conserva lo que ya había.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function rowAttributes(array $row, array $current): array
    {
        $attributes = [];

        foreach (['code', 'description', 'category', 'family', 'presentation'] as $field) {
            $attributes[$field] = $row[$field] ?? ($current[$field] ?? null);
        }

        return $attributes + [
            'isv_pct' => $row['isv_pct'],
            'units_per_box' => $row['units_per_box'],
            'list_price' => $row['list_price'],
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $new
     */
    private function priceChanged(array $current, array $new): bool
    {
        return ! BigDecimal::of((string) $current['list_price'])->isEqualTo($new['list_price'])
            || ! BigDecimal::of((string) $current['isv_pct'])->isEqualTo($new['isv_pct'])
            || (int) $current['units_per_box'] !== (int) $new['units_per_box'];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $new
     */
    private function descriptiveChanged(array $current, array $new): bool
    {
        foreach (['description', 'category', 'family', 'presentation'] as $field) {
            if (($current[$field] ?? null) !== ($new[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $before
     * @return array{action: string, row: int, code: string, attributes: array<string, mixed>, before: array<string, mixed>|null, price_changed: bool, error: string|null}
     */
    private function entry(
        string $action,
        array $row,
        array $attributes = [],
        ?array $before = null,
        bool $priceChanged = false,
        ?string $error = null,
    ): array {
        return [
            'action' => $action,
            'row' => $row['row'],
            'code' => $row['code'],
            'attributes' => $attributes,
            'before' => $before,
            'price_changed' => $priceChanged,
            'error' => $error,
        ];
    }
}
