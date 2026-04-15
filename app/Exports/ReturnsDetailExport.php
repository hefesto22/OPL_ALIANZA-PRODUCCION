<?php

namespace App\Exports;

use App\Models\ReturnLine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exportación de devoluciones — formato línea detallada (71 columnas).
 *
 * Replica exactamente el reporte que provee Jaremar:
 *   - Una fila por línea de devolución (ReturnLine).
 *   - Campos de factura repetidos en cada línea del mismo encabezado.
 *   - Todos los campos numéricos con 4 decimales (0.0000).
 *   - Encabezado verde, fuente Arial 8 pt, columnas con anchos fijos.
 *   - Primera fila congelada para facilitar la navegación.
 *
 * ─── Arquitectura de performance ─────────────────────────────────────────
 *
 * Este export procesaba históricamente con TRES bottlenecks cruzados:
 *   1. ShouldAutoSize con 71 cols × N filas = O(71·N) mediciones (timeout en 10k+)
 *   2. AfterSheet iteraba celda por celda para forzar ceros en 30 columnas
 *      (porque Maatwebsite usa fromArray con strictNullComparison=false por
 *      defecto, lo que hace que PHP evalúe 0.0 == null como TRUE y omita la
 *      celda, dejándola vacía en vez de mostrar "0.0000").
 *   3. FromQuery sin chunking → hidrataba toda la colección en RAM.
 *
 * Soluciones aplicadas:
 *   1. WithColumnWidths con anchos estáticos → cero mediciones en runtime.
 *   2. WithStrictNullComparison → respeta 0.0 como valor real, eliminando
 *      por completo los 30 loops de AfterSheet.
 *   3. WithChunkReading(500) → memoria acotada independiente del total de filas.
 *   4. WithColumnFormatting → aplica el formato 0.0000 una sola vez por columna
 *      (declarativo), no por celda.
 *
 * ─── Routing de cola ─────────────────────────────────────────────────────
 *
 * Se envía a la cola `reports` (timeout 1800s, memory 512MB) porque su perfil
 * es distinto al de jobs críticos (notificaciones, recálculos). Ver
 * config/horizon.php para los límites por supervisor.
 *
 * Columnas derivadas:
 *   CantidadCaja     → return_lines.quantity_box
 *   Cantidad         → return_lines.quantity  (unidades sueltas)
 *   ImporteTotal_Val → invoice.total − isv15 − isv18
 *
 * Columnas siempre vacías (igual en el original de Jaremar):
 *   OrdenEx, RegExonerado, RegSag
 *
 * Columnas con valor fijo de .NET DateTime vacío:
 *   CreationTime, LastModifierUserId, LastModificationTime = "01/01/0001 00:00:00"
 */
class ReturnsDetailExport implements
    FromQuery,
    ShouldQueue,
    WithChunkReading,
    WithColumnFormatting,
    WithColumnWidths,
    WithEvents,
    WithHeadings,
    WithMapping,
    WithStrictNullComparison,
    WithStyles,
    WithTitle
{
    use Exportable;

    /**
     * Cola dedicada a jobs pesados de reportería.
     *
     * Definida en config/horizon.php (supervisor-reports).
     */
    public string $queue = 'reports';

    // ─── Valor constante ─────────────────────────────────────────────────
    private const NET_NULL_DATE = '01/01/0001 00:00:00'; // .NET DateTime.MinValue

    // ─── Índices (1-based) de columnas que deben tener formato 0.0000 ─────
    // D=4  E=5  J=10  AJ=36  AK=37  AR=44 … BK=63
    private const DECIMAL_COLS = [4, 5, 10, 36, 37, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63];

    private const LAST_COL_INDEX = 71;

    public function __construct(
        private readonly ?string $dateFrom = null,
        private readonly ?string $dateTo = null,
        private readonly ?string $status = null,
        private readonly ?int $warehouseId = null,
    ) {}

    // ─── Chunking ──────────────────────────────────────────────────────────

    /**
     * Lee de DB en chunks de 500 filas → memoria del worker acotada.
     * Con 50k filas, ese es el delta entre OOM (500MB+) y estable (80MB).
     */
    public function chunkSize(): int
    {
        return 500;
    }

    // ─── Query ─────────────────────────────────────────────────────────────

    public function query(): Builder
    {
        return ReturnLine::query()
            ->with([
                'return.invoice.manifest',
                'return.warehouse',
                'return.returnReason',
                'invoiceLine',
            ])
            ->whereHas('return', function (Builder $q): void {
                if ($this->dateFrom) {
                    $q->whereDate('return_date', '>=', $this->dateFrom);
                }
                if ($this->dateTo) {
                    $q->whereDate('return_date', '<=', $this->dateTo);
                }
                if ($this->status) {
                    $q->where('status', $this->status);
                }
                if ($this->warehouseId) {
                    $q->where('warehouse_id', $this->warehouseId);
                }
            })
            ->orderBy('return_id')
            ->orderBy('line_number');
    }

    // ─── Encabezados (71 columnas — mismos nombres que Jaremar) ───────────

    public function headings(): array
    {
        return [
            'NumeroLinea',
            'ProductoId',
            'Nfactura',
            'CantidadCaja',
            'Cantidad',
            'MotivoDevolucion',
            'FechaFacturaConvertida',
            'IdDetalleDevolucion',
            'Id_Jaremar_DD',
            'LineTotal',
            'idDevolucion',
            'id_fac_detalle',
            'id_fac_encabezado',
            'Id_Jaremar_F',
            'FechaFactura',
            'FechaVencimiento',
            'Vendedorid',
            'Vendedor',
            'Clienteid',
            'Cliente',
            'Depto',
            'Municipio',
            'Barrio',
            'Almacen',
            'TipoPago',
            'DiasCred',
            'Rtn',
            'Cai',
            'OrdenEx',
            'RegExonerado',
            'RegSag',
            'Rinicial',
            'Rfinal',
            'Direccion',
            'Tel',
            'Longitud',
            'Latitud',
            'FechaLimImpre',
            'DirCasaMatriz',
            'DirSucursal',
            'NumeroPedido',
            'NumeroRuta',
            'EntregarA',
            'ImporteExcento',
            'ImporteExento_Desc',
            'ImporteExento_ISV18',
            'ImporteExento_ISV15',
            'ImporteExento_Total',
            'ImporteExonerado',
            'ImporteExonerado_Desc',
            'ImporteExonerado_ISV18',
            'ImporteExonerado_ISV15',
            'ImporteExonerado_Total',
            'ImporteGrabado',
            'ImporteGravado_Desc',
            'ImporteGravado_ISV18',
            'ImporteGravado_ISV15',
            'ImporteGravado_Total',
            'ImporteTotal_Val',
            'DescuentosRebajas',
            'Isv18',
            'Isv15',
            'Total',
            'CreationTime',
            'LastModifierUserId',
            'LastModificationTime',
            'IsDeleted',
            'NumeroFacturaLX',
            'NumeroManifiesto',
            'EstadoFactura',
            'TipoFactura',
        ];
    }

    // ─── Mapeo de datos — una fila por return_line ──────────────────────────

    public function map($line): array
    {
        $inv = $line->return?->invoice;
        $ret = $line->return;
        $mfst = $inv?->manifest;

        // Helper: convierte a float garantizando 0.0 si el valor es null.
        // Con WithStrictNullComparison, Maatwebsite respeta el 0.0 y lo
        // escribe como número real en la celda (ya no hay que forzarlo
        // en AfterSheet post-escritura).
        $f = static fn ($v): float => (float) ($v ?? 0);

        // ImporteTotal_Val = total − isv15 − isv18  (subtotal neto sin ISV)
        $importeTotalVal = $f($inv?->total) - $f($inv?->isv15) - $f($inv?->isv18);

        // Fecha con hora, igual que Jaremar (ej: "31/01/2026 00:00:00")
        $fmtDate = static fn ($d): string => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y H:i:s') : '';

        return [
            // 0  NumeroLinea
            (int) $line->line_number,
            // 1  ProductoId
            $line->product_id,
            // 2  Nfactura
            (string) ($inv?->invoice_number ?? ''),
            // 3  CantidadCaja
            $f($line->quantity_box),
            // 4  Cantidad
            $f($line->quantity),
            // 5  MotivoDevolucion — Jaremar ID del motivo; si no está cargado, usa el código
            $ret?->returnReason?->jaremar_id ?? $ret?->returnReason?->code ?? '',
            // 6  FechaFacturaConvertida
            $fmtDate($inv?->invoice_date),
            // 7  IdDetalleDevolucion
            (int) $line->id,
            // 8  Id_Jaremar_DD  (= id_fac_encabezado en Jaremar)
            $inv?->jaremar_id,
            // 9  LineTotal
            $f($line->line_total),
            // 10 idDevolucion — ID Jaremar de la devolución; si no existe, usa ID interno
            $ret?->jaremar_return_id ?? $ret?->id,
            // 11 id_fac_detalle
            $line->invoiceLine?->jaremar_line_id,
            // 12 id_fac_encabezado
            $inv?->jaremar_id,
            // 13 Id_Jaremar_F
            $inv?->jaremar_id,
            // 14 FechaFactura
            $fmtDate($inv?->invoice_date),
            // 15 FechaVencimiento
            $fmtDate($inv?->due_date),
            // 16 Vendedorid
            $inv?->seller_id,
            // 17 Vendedor
            (string) ($inv?->seller_name ?? ''),
            // 18 Clienteid
            $inv?->client_id,
            // 19 Cliente
            (string) ($inv?->client_name ?? ''),
            // 20 Depto
            (string) ($inv?->department ?? ''),
            // 21 Municipio
            (string) ($inv?->municipality ?? ''),
            // 22 Barrio
            (string) ($inv?->neighborhood ?? ''),
            // 23 Almacen
            (string) ($ret?->warehouse?->code ?? ''),
            // 24 TipoPago
            (string) ($inv?->payment_type ?? ''),
            // 25 DiasCred
            (int) ($inv?->credit_days ?? 0),
            // 26 Rtn
            (string) ($inv?->client_rtn ?? ''),
            // 27 Cai
            (string) ($inv?->cai ?? ''),
            // 28 OrdenEx      — siempre vacío en Jaremar
            '',
            // 29 RegExonerado — siempre vacío en Jaremar
            '',
            // 30 RegSag       — siempre vacío en Jaremar
            '',
            // 31 Rinicial
            (string) ($inv?->range_start ?? ''),
            // 32 Rfinal
            (string) ($inv?->range_end ?? ''),
            // 33 Direccion
            (string) ($inv?->address ?? ''),
            // 34 Tel
            (string) ($inv?->phone ?? ''),
            // 35 Longitud
            $f($inv?->longitude),
            // 36 Latitud
            $f($inv?->latitude),
            // 37 FechaLimImpre
            $fmtDate($inv?->print_limit_date),
            // 38 DirCasaMatriz
            (string) ($inv?->matriz_address ?? ''),
            // 39 DirSucursal
            (string) ($inv?->branch_address ?? ''),
            // 40 NumeroPedido
            $inv?->order_number,
            // 41 NumeroRuta
            $inv?->route_number,
            // 42 EntregarA
            (string) ($inv?->deliver_to ?? ''),
            // 43 ImporteExcento
            $f($inv?->importe_excento),
            // 44 ImporteExento_Desc
            $f($inv?->importe_exento_desc),
            // 45 ImporteExento_ISV18
            $f($inv?->importe_exento_isv18),
            // 46 ImporteExento_ISV15
            $f($inv?->importe_exento_isv15),
            // 47 ImporteExento_Total
            $f($inv?->importe_exento_total),
            // 48 ImporteExonerado
            $f($inv?->importe_exonerado),
            // 49 ImporteExonerado_Desc
            $f($inv?->importe_exonerado_desc),
            // 50 ImporteExonerado_ISV18
            $f($inv?->importe_exonerado_isv18),
            // 51 ImporteExonerado_ISV15
            $f($inv?->importe_exonerado_isv15),
            // 52 ImporteExonerado_Total
            $f($inv?->importe_exonerado_total),
            // 53 ImporteGrabado
            $f($inv?->importe_gravado),
            // 54 ImporteGravado_Desc
            $f($inv?->importe_gravado_desc),
            // 55 ImporteGravado_ISV18
            $f($inv?->importe_gravado_isv18),
            // 56 ImporteGravado_ISV15
            $f($inv?->importe_gravado_isv15),
            // 57 ImporteGravado_Total
            $f($inv?->importe_gravado_total),
            // 58 ImporteTotal_Val  (total − isv15 − isv18)
            round($importeTotalVal, 4),
            // 59 DescuentosRebajas
            $f($inv?->discounts),
            // 60 Isv18
            $f($inv?->isv18),
            // 61 Isv15
            $f($inv?->isv15),
            // 62 Total
            $f($inv?->total),
            // 63 CreationTime  — texto fijo .NET DateTime.MinValue
            self::NET_NULL_DATE,
            // 64 LastModifierUserId — texto fijo .NET DateTime.MinValue
            self::NET_NULL_DATE,
            // 65 LastModificationTime — texto fijo .NET DateTime.MinValue
            self::NET_NULL_DATE,
            // 66 IsDeleted — 0=no eliminado
            0,
            // 67 NumeroFacturaLX
            (string) ($inv?->lx_number ?? ''),
            // 68 NumeroManifiesto
            (string) ($mfst?->number ?? ''),
            // 69 EstadoFactura — 0 por defecto (Jaremar siempre recibe 0)
            0,
            // 70 TipoFactura
            (string) ($inv?->invoice_type ?? ''),
        ];
    }

    // ─── Anchos de columna (reemplazo de ShouldAutoSize) ────────────────────
    //
    // Valores fijos calibrados para los nombres de columnas Jaremar.
    // Alternativa a ShouldAutoSize que mide cada celda: O(1) en runtime.

    public function columnWidths(): array
    {
        return [
            'A' => 12,  'B' => 12, 'C' => 14, 'D' => 12, 'E' => 10,
            'F' => 18, 'G' => 22, 'H' => 18, 'I' => 14, 'J' => 12,
            'K' => 14, 'L' => 14, 'M' => 16, 'N' => 14, 'O' => 22,
            'P' => 22, 'Q' => 12, 'R' => 28, 'S' => 12, 'T' => 32,
            'U' => 18, 'V' => 18, 'W' => 18, 'X' => 12, 'Y' => 14,
            'Z' => 10, 'AA' => 16, 'AB' => 14, 'AC' => 10, 'AD' => 14,
            'AE' => 10, 'AF' => 10, 'AG' => 10, 'AH' => 36, 'AI' => 14,
            'AJ' => 14, 'AK' => 14, 'AL' => 22, 'AM' => 30, 'AN' => 30,
            'AO' => 14, 'AP' => 14, 'AQ' => 22, 'AR' => 16, 'AS' => 18,
            'AT' => 18, 'AU' => 18, 'AV' => 18, 'AW' => 18, 'AX' => 20,
            'AY' => 20, 'AZ' => 20, 'BA' => 20, 'BB' => 18, 'BC' => 18,
            'BD' => 18, 'BE' => 18, 'BF' => 18, 'BG' => 18, 'BH' => 20,
            'BI' => 14, 'BJ' => 14, 'BK' => 14, 'BL' => 20, 'BM' => 20,
            'BN' => 20, 'BO' => 12, 'BP' => 18, 'BQ' => 18, 'BR' => 14,
            'BS' => 14,
        ];
    }

    // ─── Formato numérico declarativo (reemplazo de AfterSheet per-cell) ────
    //
    // WithColumnFormatting aplica el formato a la columna entera en una
    // sola llamada, no por celda. Elimina 25 loops sobre N filas.

    public function columnFormats(): array
    {
        $formats = [];
        foreach (self::DECIMAL_COLS as $colIdx) {
            $col = Coordinate::stringFromColumnIndex($colIdx);
            $formats[$col] = '0.0000';
        }

        return $formats;
    }

    // ─── Estilos estáticos (encabezado) ────────────────────────────────────

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'name' => 'Arial',
                    'bold' => true,
                    'size' => 8,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1A7A4A'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => false,
                ],
            ],
        ];
    }

    // ─── Eventos post-escritura: SOLO operaciones O(1) por hoja ────────────
    //
    // Con WithStrictNullComparison + WithColumnFormatting + WithColumnWidths,
    // eliminamos los loops anteriores que iteraban celda por celda
    // (25 decimal cols + 3 .NET cols + 2 fallback cols × N filas).

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $ws = $event->sheet->getDelegate();
                $lastRow = $ws->getHighestRow();
                $lastColStr = Coordinate::stringFromColumnIndex(self::LAST_COL_INDEX);

                // Fuente base (Arial 8 pt) aplicada al rango completo con
                // UNA sola llamada de estilo — PhpSpreadsheet lo guarda
                // internamente como rango y no itera celda por celda.
                $ws->getStyle("A1:{$lastColStr}{$lastRow}")
                    ->getFont()
                    ->setName('Arial')
                    ->setSize(8);

                // Alineación derecha en columnas decimales (1 llamada por columna,
                // no por celda).
                foreach (self::DECIMAL_COLS as $colIdx) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    $ws->getStyle("{$col}2:{$col}{$lastRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                // Encabezado: altura de fila + bordes blancos + autofilter
                $ws->getRowDimension(1)->setRowHeight(14);

                $ws->getStyle("A1:{$lastColStr}1")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()
                    ->setRGB('FFFFFF');

                $ws->setAutoFilter("A1:{$lastColStr}1");

                // Congelar primera fila
                $ws->freezePane('A2');
            },
        ];
    }

    public function title(): string
    {
        return 'Devoluciones';
    }
}
