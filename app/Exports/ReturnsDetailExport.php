<?php

namespace App\Exports;

use App\Models\ReturnLine;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
 *   - Encabezado verde, fuente Arial 8 pt, columnas auto-ajustadas.
 *   - Primera fila congelada para facilitar la navegación.
 *
 * Columnas derivadas:
 *   CantidadCaja    → return_lines.quantity_box
 *   Cantidad        → return_lines.quantity  (unidades sueltas)
 *   ImporteTotal_Val → invoice.total − isv15 − isv18
 *
 * Columnas siempre vacías (igual en el original de Jaremar):
 *   OrdenEx, RegExonerado, RegSag
 *
 * Columnas con valor fijo de .NET DateTime vacío:
 *   CreationTime, LastModifierUserId, LastModificationTime = "01/01/0001 00:00:00"
 */
class ReturnsDetailExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithEvents, ShouldAutoSize, WithTitle
{
    // ─── Índices (1-based) de columnas que deben tener formato 0.0000 ─────
    // D=4  E=5  J=10  AJ=36  AK=37  AR=44 … BK=63
    private const DECIMAL_COLS = [4, 5, 10, 36, 37, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63];

    public function __construct(
        private readonly ?string $dateFrom    = null,
        private readonly ?string $dateTo      = null,
        private readonly ?string $status      = null,
        private readonly ?int    $warehouseId = null,
    ) {}

    // ─── Query ─────────────────────────────────────────────────────────────

    public function query(): Builder
    {
        return ReturnLine::query()
            ->with([
                'return.invoice',
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
        $inv  = $line->return?->invoice;
        $ret  = $line->return;
        $mfst = $inv?->manifest;

        // Helper: convierte a float garantizando 0.0 si el valor es null
        $f = static fn ($v): float => (float) ($v ?? 0);

        // ImporteTotal_Val = total − isv15 − isv18  (subtotal neto sin ISV)
        $importeTotalVal = $f($inv?->total) - $f($inv?->isv15) - $f($inv?->isv18);

        // Fecha con hora, igual que Jaremar (ej: "31/01/2026 00:00:00")
        $fmtDate = static fn ($d): string => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y H:i:s') : '';

        $NET_NULL = '01/01/0001 00:00:00'; // .NET DateTime.MinValue

        return [
            // 0  NumeroLinea
            (int)   $line->line_number,
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
            (int)   $line->id,
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
            (int)   ($inv?->credit_days ?? 0),
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
            // 63 CreationTime  — .NET DateTime.MinValue (escrito como texto en AfterSheet)
            '',
            // 64 LastModifierUserId — .NET DateTime.MinValue (escrito como texto en AfterSheet)
            '',
            // 65 LastModificationTime — .NET DateTime.MinValue (escrito como texto en AfterSheet)
            '',
            // 66 IsDeleted — 0=no eliminado; se fuerza en AfterSheet porque 0==null PHP suelto
            '',
            // 67 NumeroFacturaLX
            (string) ($inv?->lx_number ?? ''),
            // 68 NumeroManifiesto
            (string) ($mfst?->number ?? ''),
            // 69 EstadoFactura — se fuerza en AfterSheet porque 0==null PHP suelto
            '',
            // 70 TipoFactura
            (string) ($inv?->invoice_type ?? ''),
        ];
    }

    // ─── Estilos estáticos (encabezado) ────────────────────────────────────

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'name'  => 'Arial',
                    'bold'  => true,
                    'size'  => 8,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1A7A4A'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => false,
                ],
            ],
        ];
    }

    // ─── Eventos post-escritura: formato de celdas + congelación ───────────

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $ws       = $event->sheet->getDelegate();
                $lastRow  = $ws->getHighestRow();
                $lastCol  = 71; // 71 columnas (A–BS)

                // ── 1. Fuente base de datos (Arial 8 pt) ───────────────────
                $ws->getStyle('A2:' . Coordinate::stringFromColumnIndex($lastCol) . $lastRow)
                    ->getFont()
                    ->setName('Arial')
                    ->setSize(8);

                // ── 2. Formato 0.0000 en columnas numéricas decimales ──────
                // PROBLEMA: maatwebsite/excel usa Worksheet::fromArray() con
                // $strictNullComparison = false, lo que hace que PHP evalúe
                // 0.0 == null como TRUE y omita la celda (queda vacía).
                // SOLUCIÓN: recorrer cada celda decimal y, si está vacía,
                // escribir explícitamente 0 como TYPE_NUMERIC antes de
                // aplicar el formato.  Así 0 aparece como "0.0000" y no en blanco.
                foreach (self::DECIMAL_COLS as $colIdx) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);

                    // 2a. Forzar valor numérico 0 en celdas vacías/nulas
                    for ($r = 2; $r <= $lastRow; $r++) {
                        $cell = $ws->getCell("{$col}{$r}");
                        $v    = $cell->getValue();
                        if ($v === null || $v === '') {
                            $cell->setValueExplicit(0, DataType::TYPE_NUMERIC);
                        }
                    }

                    // 2b. Aplicar formato 0.0000 y alineación derecha a toda la columna
                    $ws->getStyle("{$col}2:{$col}{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode('0.0000');
                    $ws->getStyle("{$col}2:{$col}{$lastRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                // ── 3. Columnas con texto fijo (.NET DateTime.MinValue) ────
                // Escrito explícito como TYPE_STRING para que Numbers/Excel
                // no intente parsearlo como fecha (año 0001 da error).
                $netNullCols = [64, 65, 66]; // CreationTime, LastModifierUserId, LastModificationTime
                foreach ($netNullCols as $colIdx) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    for ($r = 2; $r <= $lastRow; $r++) {
                        $ws->getCell("{$col}{$r}")
                           ->setValueExplicit('01/01/0001 00:00:00', DataType::TYPE_STRING);
                    }
                }

                // ── 4. Columnas enteras que pueden ser 0 (skipeadas por fromArray) ─
                // IsDeleted (col 67) → siempre 0
                // EstadoFactura (col 70) → valor de invoice_status o 0 por defecto
                $zeroFallbackCols = [
                    67 => 0,  // IsDeleted
                    70 => 0,  // EstadoFactura
                ];
                foreach ($zeroFallbackCols as $colIdx => $defaultVal) {
                    $col = Coordinate::stringFromColumnIndex($colIdx);
                    for ($r = 2; $r <= $lastRow; $r++) {
                        $cell = $ws->getCell("{$col}{$r}");
                        $v    = $cell->getValue();
                        if ($v === null || $v === '') {
                            $cell->setValueExplicit($defaultVal, DataType::TYPE_NUMERIC);
                        }
                    }
                }

                // ── 5. Encabezado: altura de fila + fuente base ────────────
                $ws->getRowDimension(1)->setRowHeight(14);
                $ws->getStyle('A1:' . Coordinate::stringFromColumnIndex($lastCol) . '1')
                    ->getFont()
                    ->setName('Arial')
                    ->setSize(8);

                // ── 6. Altura uniforme para filas de datos ─────────────────
                for ($r = 2; $r <= $lastRow; $r++) {
                    $ws->getRowDimension($r)->setRowHeight(12);
                }

                // ── 7. Congelar primera fila ───────────────────────────────
                $ws->freezePane('A2');

                // ── 8. Bordes del encabezado ───────────────────────────────
                $ws->getStyle('A1:' . Coordinate::stringFromColumnIndex($lastCol) . '1')
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()
                    ->setRGB('FFFFFF');

                // ── 8. Auto-filter en encabezado ──────────────────────────
                $ws->setAutoFilter(
                    'A1:' . Coordinate::stringFromColumnIndex($lastCol) . '1'
                );
            },
        ];
    }

    public function title(): string
    {
        return 'Devoluciones';
    }
}
