<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Valida fechas de un batch de facturas agrupado por manifiesto.
 *
 * Reglas (configurables vía config/manifests.php):
 *
 *   V1 — Homogeneidad: todas las facturas de un mismo NumeroManifiesto
 *        deben tener la misma FechaFactura (en TZ Honduras). Si Jaremar
 *        manda mezclado, eso indica un error de origen — el manifiesto
 *        debe representar un único día operativo.
 *
 *   V2 — No futura: la FechaFactura nunca puede ser posterior a "hoy"
 *        en TZ Honduras. Facturación al futuro no existe legítimamente.
 *
 *   V3 — No demasiado antigua: la FechaFactura no puede tener más de
 *        max_backdate_days (default 30) respecto a "hoy". Coincide con
 *        el ciclo de declaración mensual de ISV en Honduras — facturas
 *        más viejas requieren autorización manual de admin por riesgo
 *        regulatorio (periodos fiscales ya declarados al SAR).
 *
 *   V4 — Derivación: el manifests.date se deriva de la FechaFactura del
 *        primer invoice del grupo (ya validado homogéneo). Reemplaza el
 *        antiguo now() que producía desfase entre fecha operativa real
 *        y fecha de captura.
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

            // V1 — Homogeneidad: la regla más temprana de detectar.
            // Si hay mezcla, ni siquiera tiene sentido evaluar V2/V3 porque
            // no hay una "fecha del manifiesto" coherente que validar.
            if (config('manifests.dates.reject_mixed_dates', true)) {
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

            // Todas las fechas son iguales — tomamos la primera como referencia.
            $referenceDate = $this->normalizeToDate(
                $manifestInvoices[0]['FechaFactura'] ?? null
            );

            // Si la fecha no parsea, es error de schema (ya cubierto por
            // ApiInvoiceValidatorService) pero protegemos por defensa.
            if ($referenceDate === null) {
                $invalid[] = [
                    'manifiesto' => $manifestNumber,
                    'motivo' => 'FECHA_FACTURA_INVALIDA',
                    'detalle' => [
                        'instruccion' => 'La FechaFactura no se pudo interpretar como una fecha válida.',
                    ],
                    'total_facturas' => count($facturas),
                    'facturas' => $facturas,
                ];

                continue;
            }

            // V2 — No futura.
            if (! config('manifests.dates.allow_future', false)) {
                if ($this->isFuture($referenceDate)) {
                    $invalid[] = [
                        'manifiesto' => $manifestNumber,
                        'motivo' => 'FECHA_FACTURA_FUTURA',
                        'detalle' => [
                            'fecha_factura' => $referenceDate,
                            'hoy_servidor' => $this->today(),
                            'instruccion' => 'La FechaFactura no puede ser posterior a la fecha actual del servidor (Honduras).',
                        ],
                        'total_facturas' => count($facturas),
                        'facturas' => $facturas,
                    ];

                    continue;
                }
            }

            // V3 — No demasiado antigua.
            $maxBackdate = (int) config('manifests.dates.max_backdate_days', 30);
            $daysOld = $this->daysBackdated($referenceDate);

            if ($daysOld > $maxBackdate) {
                $invalid[] = [
                    'manifiesto' => $manifestNumber,
                    'motivo' => 'FECHA_FACTURA_DEMASIADO_ANTIGUA',
                    'detalle' => [
                        'fecha_factura' => $referenceDate,
                        'hoy_servidor' => $this->today(),
                        'dias_antiguedad' => $daysOld,
                        'limite_dias' => $maxBackdate,
                        'instruccion' => "La FechaFactura tiene {$daysOld} días de antigüedad y supera el límite de {$maxBackdate} días. Contacte a Hosana para cargar este lote desde el panel administrativo.",
                    ],
                    'total_facturas' => count($facturas),
                    'facturas' => $facturas,
                ];

                continue;
            }

            // Pasó las 3 validaciones — manifiesto válido.
            $valid[] = [
                'manifiesto' => $manifestNumber,
                'fecha_operativa' => $referenceDate,
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
