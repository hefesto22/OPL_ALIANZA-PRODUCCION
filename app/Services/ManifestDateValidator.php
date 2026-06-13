<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Valida fechas de un batch de facturas agrupado por manifiesto.
 *
 * Reglas (configurables vía config/manifests.php):
 *
 *   V1 — Homogeneidad (OPCIONAL, default OFF): si reject_mixed_dates=true,
 *        todas las facturas de un mismo NumeroManifiesto deben compartir
 *        FechaFactura. Por requerimiento de Jaremar el default es permitir
 *        fechas mezcladas: un manifiesto es un "lote de carga", no un único
 *        día operativo.
 *
 *   V2 — No futura (POR FACTURA): ninguna FechaFactura puede ser posterior
 *        a "hoy" en TZ Honduras. Facturación al futuro no existe.
 *
 *   V3 — No demasiado antigua (POR FACTURA): ninguna FechaFactura puede
 *        tener más de max_backdate_days (default 30) respecto a "hoy".
 *        Coincide con el ciclo de declaración mensual de ISV en Honduras.
 *        Si UNA sola factura excede el límite, se rechaza el manifiesto
 *        COMPLETO (atómico) para forzar la corrección en origen.
 *
 *   V4 — Fecha del manifiesto (manifest_date_source): por default 'upload'
 *        → manifests.date = día de carga (hoy). Con 'invoice' se deriva de
 *        la FechaFactura del grupo (modo histórico, requiere homogeneidad).
 *        En ambos modos, invoice.invoice_date conserva la fecha real de
 *        cada factura — solo cambia la fecha de agrupación del manifiesto.
 *
 * Con fechas mezcladas permitidas, V2/V3 se evalúan factura por factura
 * (no solo la primera), de modo que una factura inválida en cualquier
 * posición rechaza el manifiesto.
 *
 * El servicio es sin estado. Recibe arrays, retorna arrays — no toca BD
 * ni dispara side effects. Esto lo hace 100% testeable como unit y
 * reusable desde cualquier entrypoint (API, comando, Job).
 */
class ManifestDateValidator
{
    /**
     * Valida el batch completo y retorna estructura consumible por el
     * controller. Agrupa por NumeroManifiesto y aplica V1, V2, V3 a cada
     * grupo. La primera regla que falla por manifiesto se reporta — no
     * acumula errores múltiples por el mismo manifiesto (menos ruido para
     * Jaremar al diagnosticar).
     *
     * @param  array  $invoices  Payload crudo de Jaremar (cada item con
     *                           keys 'NumeroManifiesto', 'FechaFactura',
     *                           'Nfactura', ...).
     * @return array{
     *     has_errors: bool,
     *     invalid_manifests: array<int, array{
     *         manifiesto: string,
     *         motivo: string,
     *         detalle: array,
     *         total_facturas: int,
     *         facturas: array<int, string>,
     *     }>,
     *     valid_manifests: array<int, array{
     *         manifiesto: string,
     *         fecha_operativa: string,
     *         total_facturas: int,
     *     }>,
     * }
     */
    public function validateBatch(array $invoices): array
    {
        $invalid = [];
        $valid = [];

        $grouped = $this->groupByManifest($invoices);

        foreach ($grouped as $manifestNumber => $manifestInvoices) {
            $manifestNumber = (string) $manifestNumber;
            $facturas = array_map(
                fn ($inv) => (string) ($inv['Nfactura'] ?? '(sin Nfactura)'),
                $manifestInvoices
            );

            // V1 — Homogeneidad: OPCIONAL. Solo se exige si el negocio
            // activa reject_mixed_dates. Por default Jaremar puede mezclar
            // fechas en un mismo manifiesto, así que se omite.
            if (config('manifests.dates.reject_mixed_dates', false)) {
                $mixed = $this->findMixedDates($manifestInvoices);

                if (! empty($mixed)) {
                    $invalid[] = [
                        'manifiesto' => $manifestNumber,
                        'motivo' => 'FECHAS_MEZCLADAS',
                        'detalle' => [
                            'fechas_encontradas' => array_keys($mixed),
                            'facturas_por_fecha' => $mixed,
                            'instruccion' => 'Cada NumeroManifiesto debe contener facturas de una única FechaFactura. Separe el manifiesto en lotes por fecha y reenvíe.',
                        ],
                        'total_facturas' => count($facturas),
                        'facturas' => $facturas,
                    ];

                    continue;
                }
            }

            // V2/V3 — Validación POR FACTURA (formato, no futura, no muy
            // antigua). Una sola factura inválida rechaza el manifiesto
            // completo (atómico) para forzar corrección en origen.
            $violation = $this->findDateViolation($manifestInvoices);

            if ($violation !== null) {
                $invalid[] = array_merge(
                    ['manifiesto' => $manifestNumber],
                    $violation,
                    ['total_facturas' => count($facturas), 'facturas' => $facturas],
                );

                continue;
            }

            // V4 — Fecha del manifiesto según config (default: día de carga).
            $valid[] = [
                'manifiesto' => $manifestNumber,
                'fecha_operativa' => $this->resolveManifestOperationalDate($manifestInvoices),
                'total_facturas' => count($facturas),
            ];
        }

        return [
            'has_errors' => ! empty($invalid),
            'invalid_manifests' => $invalid,
            'valid_manifests' => $valid,
        ];
    }

    /**
     * V2/V3 por factura. Revisa TODAS las facturas del grupo y devuelve la
     * primera violación por prioridad (formato → futura → demasiado antigua),
     * con la lista de facturas afectadas. Devuelve null si todas son válidas.
     *
     * El rechazo es a nivel manifiesto: basta una factura mala para invalidar
     * el grupo completo.
     *
     * @return array{motivo: string, detalle: array}|null
     */
    public function findDateViolation(array $manifestInvoices): ?array
    {
        $allowFuture = (bool) config('manifests.dates.allow_future', false);
        $maxBackdate = (int) config('manifests.dates.max_backdate_days', 30);

        $invalidFormat = [];
        $future = [];
        $tooOld = [];

        foreach ($manifestInvoices as $invoice) {
            $factura = (string) ($invoice['Nfactura'] ?? '(sin Nfactura)');
            $date = $this->normalizeToDate($invoice['FechaFactura'] ?? null);

            if ($date === null) {
                $invalidFormat[] = $factura;

                continue;
            }

            if (! $allowFuture && $this->isFuture($date)) {
                $future[$factura] = $date;

                continue;
            }

            $daysOld = $this->daysBackdated($date);
            if ($daysOld > $maxBackdate) {
                $tooOld[$factura] = ['fecha' => $date, 'dias' => $daysOld];
            }
        }

        if (! empty($invalidFormat)) {
            return [
                'motivo' => 'FECHA_FACTURA_INVALIDA',
                'detalle' => [
                    'facturas_afectadas' => array_values($invalidFormat),
                    'instruccion' => 'Una o más FechaFactura no se pudieron interpretar como fecha válida.',
                ],
            ];
        }

        if (! empty($future)) {
            return [
                'motivo' => 'FECHA_FACTURA_FUTURA',
                'detalle' => [
                    'hoy_servidor' => $this->today(),
                    'facturas_futuras' => $future,
                    'instruccion' => 'Una o más facturas tienen FechaFactura posterior a hoy (Honduras). Corrija el origen.',
                ],
            ];
        }

        if (! empty($tooOld)) {
            return [
                'motivo' => 'FECHA_FACTURA_DEMASIADO_ANTIGUA',
                'detalle' => [
                    'hoy_servidor' => $this->today(),
                    'limite_dias' => $maxBackdate,
                    'facturas_antiguas' => $tooOld,
                    'instruccion' => "Una o más facturas superan el límite de {$maxBackdate} días de antigüedad. El manifiesto se rechaza completo; corrija el origen o cargue desde el panel administrativo.",
                ],
            ];
        }

        return null;
    }

    /**
     * V4 — Resuelve la fecha del manifiesto según config:
     *   'upload'  (default) → hoy (día de carga, TZ Honduras).
     *   'invoice'           → derivada de la FechaFactura del grupo (legacy).
     *
     * En modo 'upload' no importa la fecha de las facturas: el manifiesto es
     * el lote del día en que se cargó. invoice.invoice_date no se altera.
     */
    public function resolveManifestOperationalDate(array $manifestInvoices): ?string
    {
        $source = (string) config('manifests.dates.manifest_date_source', 'upload');

        if ($source === 'invoice') {
            return $this->resolveManifestDate($manifestInvoices);
        }

        return $this->today();
    }

    /**
     * V1 — Devuelve las fechas distintas encontradas dentro del grupo
     * y qué facturas pertenecen a cada una. Retorna [] si todas son
     * iguales (manifiesto homogéneo).
     *
     * @param  array  $manifestInvoices  Facturas de un único NumeroManifiesto
     * @return array<string, array<int, string>> ['YYYY-MM-DD' => ['Nfactura1', ...]]
     */
    public function findMixedDates(array $manifestInvoices): array
    {
        $byDate = [];

        foreach ($manifestInvoices as $invoice) {
            $date = $this->normalizeToDate($invoice['FechaFactura'] ?? null);
            $factura = (string) ($invoice['Nfactura'] ?? '(sin Nfactura)');

            if ($date === null) {
                continue;
            }

            $byDate[$date][] = $factura;
        }

        // Si hay 1 sola fecha distinta, no hay mezcla.
        return count($byDate) > 1 ? $byDate : [];
    }

    /**
     * V4 — Resuelve la fecha operativa del manifiesto.
     *
     * Asume que el grupo YA pasó V1 (es homogéneo) — toma la primera
     * factura como referencia. Si por alguna razón el grupo no es
     * homogéneo, devuelve la MAX(FechaFactura) como fallback seguro
     * (la fecha más reciente representa mejor el "trabajo del día").
     *
     * Reemplaza el antiguo now()->toDateString() que producía el desfase
     * entre fecha operativa real y fecha de captura.
     */
    public function resolveManifestDate(array $manifestInvoices): ?string
    {
        $dates = [];

        foreach ($manifestInvoices as $invoice) {
            $date = $this->normalizeToDate($invoice['FechaFactura'] ?? null);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        if (empty($dates)) {
            return null;
        }

        // Fallback defensivo: si hay mezcla (no debería llegar acá si V1
        // está activo), tomamos la MAX como aproximación más segura.
        sort($dates);

        return end($dates);
    }

    /**
     * Normaliza un timestamp crudo (cualquier formato de Jaremar) a
     * fecha calendario YYYY-MM-DD en TZ Honduras.
     *
     * Formatos soportados:
     *   "2026-03-22T00:00:00.000Z"  → "2026-03-21" si la conversión UTC→TGU cruza día
     *   "2026-03-22T00:00:00Z"      → idem
     *   "2026-03-22"                → "2026-03-22"
     *   "22/03/2026"                → "2026-03-22"  (formato latinoamericano)
     *
     * Nota crítica sobre timezone: un timestamp UTC "T00:00:00Z" cae a
     * las 18:00 del día ANTERIOR en Honduras (UTC-6). Para evitar ese
     * sesgo sutil, Jaremar siempre debería mandar timestamps con offset
     * explícito o fechas planas (sin hora). Si llega "Z" puro a las
     * 00:00, asumimos que Jaremar quiso decir "ese día calendario" y
     * NO restamos 6 horas — usamos la parte de fecha tal cual viene
     * en UTC. Para timestamps con hora real (T03:00:00Z, etc.) sí
     * convertimos a TZ Honduras antes de extraer la fecha.
     */
    public function normalizeToDate(?string $rawDate): ?string
    {
        if (! $rawDate) {
            return null;
        }

        try {
            // Caso 1: formato latinoamericano dd/mm/yyyy.
            // Carbon::parse() lo leería como m/d/Y (americano) y rompería.
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $rawDate)) {
                return Carbon::createFromFormat('d/m/Y', $rawDate)->toDateString();
            }

            // Caso 2: timestamp UTC a medianoche exacta (T00:00:00.000Z).
            // Es el patrón típico de Jaremar para "este día calendario".
            // Tomamos la parte de fecha sin convertir a TZ local — si
            // restáramos 6h caería al día anterior y eso NO es lo que
            // Jaremar quiso decir.
            if (preg_match('/^(\d{4}-\d{2}-\d{2})T00:00:00(\.\d+)?Z$/', $rawDate, $m)) {
                return $m[1];
            }

            // Caso 3: cualquier otro formato (con hora real o sin zona).
            // Convertimos a TZ Honduras y tomamos la fecha calendario.
            return Carbon::parse($rawDate)
                ->setTimezone($this->timezone())
                ->toDateString();

        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Agrupa el batch por NumeroManifiesto preservando el orden de
     * aparición. Equivalente a collect()->groupBy() pero sin la
     * dependencia de Collection (más liviano para validación).
     *
     * @return array<string, array>
     */
    protected function groupByManifest(array $invoices): array
    {
        $groups = [];

        foreach ($invoices as $invoice) {
            $number = (string) ($invoice['NumeroManifiesto'] ?? '');
            if ($number === '') {
                continue;
            }
            $groups[$number][] = $invoice;
        }

        return $groups;
    }

    /**
     * "Hoy" como fecha calendario YYYY-MM-DD en TZ Honduras.
     * Usa CarbonImmutable para evitar mutación accidental.
     */
    protected function today(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    protected function isFuture(string $date): bool
    {
        return $date > $this->today();
    }

    /**
     * Días entre $date y hoy. Si $date es hoy → 0. Si es ayer → 1.
     * Si es futuro → número negativo (no usado por V3 pero útil para tests).
     */
    protected function daysBackdated(string $date): int
    {
        $today = CarbonImmutable::createFromFormat('Y-m-d', $this->today(), $this->timezone())->startOfDay();
        $target = CarbonImmutable::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay();

        return (int) $target->diffInDays($today, false);
    }

    protected function timezone(): string
    {
        return (string) config('manifests.dates.timezone', 'America/Tegucigalpa');
    }
}
