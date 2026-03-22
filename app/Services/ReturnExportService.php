<?php

namespace App\Services;

use App\Models\InvoiceReturn;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReturnExportService
{
    /**
     * Transforma una colección de devoluciones al formato exacto de Jaremar.
     * Los campos numéricos se guardan como strings con formato "##DECIMAL##valor"
     * para que ReturnExporter los convierta a números con 6 decimales en el output.
     */
    public function toJaremarArray(Collection $returns): array
    {
        return $returns->map(function (InvoiceReturn $return) {
            return [
                'devolucion'       => $return->jaremar_return_id ?? (string) $return->id,
                'factura'          => $return->invoice->invoice_number ?? '',
                'clienteid'        => $return->client_id ?? '',
                'cliente'          => $return->client_name ?? '',
                'fecha'            => $return->return_date
                    ? Carbon::parse($return->return_date)->format('Y-m-d\TH:i:s')
                    : '',
                'total'            => $this->n6($return->total),
                'almacen'          => $return->warehouse->code ?? '',
                'idConcepto'       => $return->returnReason->jaremar_id
                    ?? $return->returnReason->code
                    ?? '',
                'concepto'         => $return->returnReason->description ?? '',
                'numeroManifiesto' => $return->manifest_number
                    ?? $return->manifest->number
                    ?? '',
                'fechaProcesado'   => $return->processed_date
                    ? Carbon::parse($return->processed_date)->format('Y-m-d\TH:i:s')
                    : null,
                'horaProcesado'    => $return->processed_time
                    ? Carbon::parse($return->processed_time)->format('H:i:s')
                    : null,
                'lineasDevolucion' => $return->lines->map(fn($line) => [
                    'productoId'  => $line->product_id,
                    'producto'    => $line->product_description,
                    'cantidad'    => $this->n6($line->quantity),
                    'numeroLinea' => (string) $line->line_number,
                    'lineTotal'   => $this->n6($line->line_total),
                ])->toArray(),
            ];
        })->toArray();
    }

    /**
     * Formatea un número con exactamente 6 decimales como string.
     * ReturnExporter usa esto directamente para XML/CSV,
     * y para JSON hace un post-process para convertirlo a número.
     */
    public function n6(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    /**
     * Carga las relaciones necesarias en la query de devoluciones.
     */
    public function withRelations($query)
    {
        return $query->with([
            'invoice:id,invoice_number',
            'warehouse:id,code',
            'returnReason:id,jaremar_id,code,description',
            'manifest:id,number',
            'lines:id,return_id,product_id,product_description,quantity,line_number,line_total',
        ]);
    }
}