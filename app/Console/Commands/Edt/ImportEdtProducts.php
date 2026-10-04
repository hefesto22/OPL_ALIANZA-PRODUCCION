<?php

namespace App\Console\Commands\Edt;

use App\Imports\Edt\EdtPriceListImport;
use App\Models\Edt\EdtSupplier;
use App\Services\Edt\EdtProductImportService;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Exceptions\SheetNotFoundException;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Carga o actualiza el catálogo del EDT desde el Excel de precios del cliente.
 *
 * Siempre conviene correr primero con --dry-run: muestra qué productos
 * crearía, qué precios cambiarían (y entrarían al historial) y qué filas
 * tienen errores, sin guardar nada.
 *
 *   php artisan edt:importar-productos "/ruta/Calculo Precios - EDT Honduras.xlsx" --dry-run
 *   php artisan edt:importar-productos "/ruta/Calculo Precios - EDT Honduras.xlsx"
 *
 * Es seguro repetirlo: un archivo ya cargado no cambia nada. Sirve también
 * para cargar una lista de precios nueva del proveedor con el mismo formato.
 */
class ImportEdtProducts extends Command
{
    protected $signature = 'edt:importar-productos
        {archivo : Ruta del Excel (.xlsx)}
        {--proveedor=EDT : Código del proveedor EDT al que pertenecen los productos}
        {--hoja=Pedido EDTH : Nombre de la hoja con el catálogo}
        {--dry-run : Solo mostrar lo que haría, sin guardar nada}
        {--force : Aplicar sin pedir confirmación}';

    protected $description = 'Carga o actualiza el catálogo de productos del EDT desde el Excel de precios';

    public function handle(EdtProductImportService $service): int
    {
        $path = (string) $this->argument('archivo');

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $supplier = EdtSupplier::query()->where('code', mb_strtoupper((string) $this->option('proveedor')))->first();

        if (! $supplier) {
            $this->error("No existe el proveedor EDT con código {$this->option('proveedor')}. Créalo primero en EDT Sistema → Proveedores.");

            return self::FAILURE;
        }

        $import = new EdtPriceListImport((string) $this->option('hoja'));

        try {
            Excel::import($import, $path);
        } catch (SheetNotFoundException) {
            $this->error("El archivo no tiene una hoja llamada \"{$this->option('hoja')}\".");

            return self::FAILURE;
        }

        $parsed = $service->parse($import->rows);
        $plan = $service->plan($parsed['rows'], $supplier);

        $this->report($path, $supplier, $parsed, $plan);

        $errors = array_merge($parsed['errors'], $this->planErrors($plan));
        if ($errors !== []) {
            $this->newLine();
            $this->error('Hay filas con errores; corrígelas en el Excel y vuelve a correr. No se guardó nada.');

            return self::FAILURE;
        }

        $changes = array_filter($plan, fn (array $entry): bool => in_array($entry['action'], ['create', 'update'], true));

        if ($changes === []) {
            $this->info('El catálogo ya está al día con este archivo. No hay nada que guardar.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('MODO PRUEBA: no se guardó nada. Para aplicarlo, corre el mismo comando sin --dry-run.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Aplicar estos cambios?', false)) {
            $this->warn('Cancelado. No se guardó nada.');

            return self::SUCCESS;
        }

        try {
            $service->apply($changes, basename($path));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Listo: catálogo actualizado. Cada producto nuevo y cada cambio de precio quedó en su historial.');

        return self::SUCCESS;
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, errors: list<string>}  $parsed
     * @param  list<array<string, mixed>>  $plan
     */
    private function report(string $path, EdtSupplier $supplier, array $parsed, array $plan): void
    {
        $count = fn (callable $filter): int => count(array_filter($plan, $filter));

        $this->line("Archivo: {$path}");
        $this->line("Hoja: {$this->option('hoja')} · Proveedor: {$supplier->code} ({$supplier->name})");
        $this->newLine();

        $this->table(['', 'Filas'], [
            ['Filas con producto leídas', count($parsed['rows'])],
            ['Productos nuevos', $count(fn (array $e): bool => $e['action'] === 'create')],
            ['Cambios de precio (van al historial)', $count(fn (array $e): bool => $e['action'] === 'update' && $e['price_changed'])],
            ['Cambios de descripción u otros datos', $count(fn (array $e): bool => $e['action'] === 'update' && ! $e['price_changed'])],
            ['Filas repetidas o sin cambios', $count(fn (array $e): bool => $e['action'] === 'unchanged')],
            ['Errores', count($parsed['errors']) + $count(fn (array $e): bool => $e['action'] === 'error')],
        ]);

        $priceChanges = array_values(array_filter($plan, fn (array $e): bool => $e['action'] === 'update' && $e['price_changed']));

        if ($priceChanges !== []) {
            $this->newLine();
            $this->line('Cambios de precio:');
            $this->table(
                ['Fila', 'Código', 'Descripción', 'Lista antes', 'Lista ahora', 'ISV', 'U×C'],
                array_map(fn (array $e): array => [
                    $e['row'],
                    $e['code'],
                    mb_strimwidth((string) $e['attributes']['description'], 0, 40, '…'),
                    $e['before']['list_price'],
                    $e['attributes']['list_price'],
                    $this->changeText($e['before']['isv_pct'], $e['attributes']['isv_pct']),
                    $this->changeText($e['before']['units_per_box'], $e['attributes']['units_per_box']),
                ], $priceChanges),
            );
        }

        foreach (array_merge($parsed['errors'], $this->planErrors($plan)) as $error) {
            $this->error($error);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return list<string>
     */
    private function planErrors(array $plan): array
    {
        return array_values(array_map(
            fn (array $e): string => "Fila {$e['row']} (código {$e['code']}): {$e['error']}",
            array_filter($plan, fn (array $e): bool => $e['action'] === 'error'),
        ));
    }

    private function changeText(mixed $before, mixed $after): string
    {
        return (string) $before === (string) $after ? (string) $after : "{$before} → {$after}";
    }
}
