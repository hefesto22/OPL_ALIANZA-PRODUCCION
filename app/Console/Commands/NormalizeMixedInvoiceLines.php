<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill: normaliza quantity_fractions en líneas MIXTAS históricas.
 *
 * Contexto (2026-07-04): Jaremar puede enviar líneas con CantidadCaja > 0 Y
 * CantidadFracciones > 0 a la vez (ej. bonificación "1 caja + 56 unidades",
 * factura 002-001-01-03871160). El importador solo normalizaba el caso CJ puro
 * (fracciones = 0), así que en las mixtas quantity_fractions quedó con SOLO las
 * sueltas y la caja aparte en quantity_box. Eso rompía:
 *   - Impresión ESC/P: la caja desaparecía de la columna Cj.
 *   - Devoluciones: disponible < mercadería física entregada.
 *   - Precio por fracción (total / quantity_fractions) en mixtas pagadas.
 *
 * Regla matemática (la misma de BoxEquivalence::totalFractions y la Sublista):
 * si fractions < cajas × factor, las cajas NO pueden estar incluidas → se suman.
 *
 * IDEMPOTENTE: tras normalizar, la condición del WHERE deja de cumplirse, por
 * lo que re-ejecutar el comando (o un retry) no duplica el efecto. La condición
 * se repite dentro del UPDATE para que sea segura incluso bajo concurrencia.
 */
class NormalizeMixedInvoiceLines extends Command
{
    protected $signature = 'invoice-lines:normalize-mixed
                            {--dry-run : Solo contar y mostrar las líneas afectadas, sin modificar}
                            {--chunk=500 : Filas por lote}';

    protected $description = 'Normaliza quantity_fractions en líneas mixtas históricas (cajas embebidas de Jaremar)';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $baseQuery = fn () => DB::table('invoice_lines')
            ->whereRaw('quantity_fractions < quantity_box * conversion_factor')
            ->where('quantity_box', '>', 0);

        $total = $baseQuery()->count();
        $this->info("Líneas mixtas sin normalizar: {$total}");

        if ($total === 0) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $sample = $baseQuery()
                ->orderBy('id')
                ->limit(10)
                ->get(['id', 'invoice_id', 'product_id', 'unit_sale', 'quantity_box', 'quantity_fractions', 'conversion_factor']);

            $this->table(
                ['id', 'invoice_id', 'product_id', 'UM', 'cajas', 'fracciones', 'factor', 'fracciones normalizadas'],
                $sample->map(fn ($l) => [
                    $l->id,
                    $l->invoice_id,
                    $l->product_id,
                    $l->unit_sale,
                    $l->quantity_box,
                    $l->quantity_fractions,
                    $l->conversion_factor,
                    (float) $l->quantity_box * (int) $l->conversion_factor + (float) $l->quantity_fractions,
                ])->all()
            );
            $this->comment('Dry-run: no se modificó nada.');

            return self::SUCCESS;
        }

        $updated = 0;
        while (true) {
            $ids = $baseQuery()->orderBy('id')->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // La condición se repite en el UPDATE: si otro proceso normalizó la
            // fila entre el SELECT y el UPDATE, no se suma dos veces.
            $updated += DB::table('invoice_lines')
                ->whereIn('id', $ids)
                ->whereRaw('quantity_fractions < quantity_box * conversion_factor')
                ->where('quantity_box', '>', 0)
                ->update([
                    'quantity_fractions' => DB::raw('quantity_box * conversion_factor + quantity_fractions'),
                    'updated_at' => now(),
                ]);
        }

        $this->info("Líneas normalizadas: {$updated}");

        Log::channel('imports')->info('Backfill de líneas mixtas ejecutado', [
            'detectadas' => $total,
            'normalizadas' => $updated,
        ]);

        return self::SUCCESS;
    }
}
