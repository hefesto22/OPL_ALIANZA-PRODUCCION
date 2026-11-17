<?php

namespace App\Filament\Resources\Manifests\Schemas;

use App\Models\Manifest;
use App\Models\User;
use Closure;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Vista del manifiesto (17/11/2026): tres franjas compactas para que la
 * tabla de facturas —donde se trabaja— quede a la vista sin bajar:
 *
 *   1. Cabecera: fecha, proveedor, estado, plazo de devoluciones, bodegas.
 *   2. Facturas: conteos que filtran la tabla al hacer clic.
 *   3. Resumen Financiero (solo roles con dinero): la ecuación del
 *      manifiesto, el avance del depósito y, si hay varias bodegas, el
 *      desglose por bodega en una tabla.
 */
class ManifestInfolist
{
    private const ROLES_CON_DINERO = ['super_admin', 'admin', 'encargado', 'finance'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::cabecera(),
            self::facturas(),
            self::resumenFinanciero(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 1. Cabecera
    // ══════════════════════════════════════════════════════════════════

    private static function cabecera(): Section
    {
        return Section::make()
            ->compact()
            ->columnSpanFull()
            ->columns(['default' => 2, 'md' => 3, 'xl' => 6])
            ->schema([
                TextEntry::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y'),

                TextEntry::make('supplier.name')
                    ->label('Proveedor')
                    ->placeholder('—')
                    ->columnSpan(['default' => 2, 'md' => 2, 'xl' => 2]),

                TextEntry::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'imported' => 'info',
                        'closed' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'pending' => 'heroicon-o-clock',
                        'processing' => 'heroicon-o-arrow-path',
                        'imported' => 'heroicon-o-inbox-arrow-down',
                        'closed' => 'heroicon-o-lock-closed',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pendiente',
                        'processing' => 'Procesando',
                        'imported' => 'Importado',
                        'closed' => 'Cerrado',
                        default => $state,
                    }),

                // Hasta cuándo la bodega puede registrar devoluciones.
                TextEntry::make('returns_window')
                    ->label('Devoluciones')
                    ->badge()
                    ->state(fn (Manifest $record): string => self::plazoDevoluciones($record))
                    ->color(fn (Manifest $record): string => self::colorPlazo($record))
                    ->icon(fn (Manifest $record): ?string => match (true) {
                        $record->returnsDeadline() === null => null,
                        $record->returnsWindowClosed() => 'heroicon-o-lock-closed',
                        default => 'heroicon-o-clock',
                    })
                    ->tooltip(fn (Manifest $record): ?string => self::tooltipPlazo($record)),

                // Bodegas que tienen facturas en el manifiesto (antes
                // "Bodega Principal", que en los multi-bodega salía vacío).
                TextEntry::make('bodegas')
                    ->label('Bodegas')
                    ->badge()
                    ->state(fn (Manifest $record): array => self::codigosBodega($record))
                    ->color(fn (string $state): string => match ($state) {
                        'OAC' => 'info',
                        'OAO' => 'success',
                        'OAS' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                TextEntry::make('closed_info')
                    ->label('Cerrado')
                    ->columnSpanFull()
                    ->visible(fn (Manifest $record): bool => $record->isClosed())
                    ->state(fn (Manifest $record): string => 'Por '.($record->closedBy?->name ?? '—')
                        .($record->closed_at ? ' el '.$record->closed_at->format('d/m/Y').' a las '.$record->closed_at->format('H:i') : '')),
            ]);
    }

    private static function plazoDevoluciones(Manifest $record): string
    {
        $cierre = $record->returnsDeadline();

        return match (true) {
            $cierre === null => 'Sin límite',
            $record->returnsWindowClosed() => 'Cerradas el '.$cierre->format('d/m/Y'),
            default => 'Abiertas hasta el '.$cierre->format('d/m'),
        };
    }

    private static function colorPlazo(Manifest $record): string
    {
        return match (true) {
            $record->returnsDeadline() === null => 'info',
            $record->returnsWindowClosed() => 'gray',
            $record->remainingReturnBusinessDays() <= 1 => 'warning',
            default => 'success',
        };
    }

    private static function tooltipPlazo(Manifest $record): ?string
    {
        if ($record->returnsDeadline() === null || $record->returnsWindowClosed()) {
            return null;
        }

        $dias = $record->remainingReturnBusinessDays();

        return 'Cierra el '.$record->returnsDeadlineLabel().' · '
            .($dias === 1 ? 'queda 1 día hábil' : "quedan {$dias} días hábiles");
    }

    /**
     * @return array<int, string>
     */
    private static function codigosBodega(Manifest $record): array
    {
        $codigos = $record->warehouseTotals
            ->loadMissing('warehouse:id,code')
            ->map(fn ($total): ?string => $total->warehouse?->code)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $codigos !== [] ? $codigos : array_values(array_filter([$record->warehouse?->code]));
    }

    // ══════════════════════════════════════════════════════════════════
    // 2. Facturas — cada conteo filtra la tabla de abajo
    // ══════════════════════════════════════════════════════════════════

    private static function facturas(): Section
    {
        return Section::make('Facturas')
            ->icon('heroicon-o-document-text')
            ->compact()
            ->columnSpanFull()
            ->columns(['default' => 2, 'md' => 5])
            ->schema([
                self::conteoFacturas('invoices_total', 'Enviadas', function (Manifest $record): int {
                    /** @var User $user */
                    $user = Auth::user();
                    $query = $record->invoices();
                    if ($user->isWarehouseUser()) {
                        $query->whereIn('warehouse_id', $user->warehouseIds());
                    }

                    return $query->count();
                }, [], null),

                self::conteoFacturas('invoices_summary_accepted', 'Aceptadas', function (Manifest $record): int {
                    $summary = self::resumenFacturas($record);

                    return ($summary['imported']['count'] ?? 0)
                         + ($summary['partial_return']['count'] ?? 0)
                         + ($summary['returned']['count'] ?? 0);
                }, ['imported', 'partial_return', 'returned'], 'success'),

                self::conteoFacturas('invoices_summary_pending', 'Pend. revisión',
                    fn (Manifest $record): int => self::resumenFacturas($record)['pending_warehouse']['count'] ?? 0,
                    ['pending_warehouse'], 'warning'),

                self::conteoFacturas('invoices_summary_rejected', 'Rechazadas',
                    fn (Manifest $record): int => self::resumenFacturas($record)['rejected']['count'] ?? 0,
                    ['rejected'], 'danger'),

                TextEntry::make('returns_total')
                    ->label('Devoluciones')
                    ->size(TextSize::Large)
                    ->weight('bold')
                    ->state(function (Manifest $record): int {
                        /** @var User $user */
                        $user = Auth::user();
                        if ($user->isWarehouseUser()) {
                            return (int) $record->returns()
                                ->whereIn('warehouse_id', $user->warehouseIds())
                                ->where('status', '!=', 'cancelled')
                                ->count();
                        }

                        return (int) $record->returns_count;
                    })
                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'gray'),
            ]);
    }

    /**
     * @param  array<int, string>  $statuses  estados que filtra al hacer clic ([] = todas)
     */
    private static function conteoFacturas(string $name, string $label, Closure $state, array $statuses, ?string $color): TextEntry
    {
        $filtro = $statuses === [] ? '[]' : "['".implode("','", $statuses)."']";

        return TextEntry::make($name)
            ->label($label)
            ->size(TextSize::Large)
            ->weight('bold')
            ->state($state)
            ->color(fn ($state): ?string => $color === null ? null : ((int) $state > 0 ? $color : 'gray'))
            ->extraAttributes([
                'class' => 'cursor-pointer select-none rounded-lg transition-all hover:bg-gray-100 dark:hover:bg-white/5',
                'style' => 'cursor:pointer;',
                'wire:click' => "\$dispatch('filterInvoicesByStatus', { statuses: {$filtro} })",
                'title' => 'Filtrar la tabla de facturas',
            ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function resumenFacturas(Manifest $record): array
    {
        /** @var User $user */
        $user = Auth::user();

        return $record->getInvoicesSummary($user->warehouseIds());
    }

    // ══════════════════════════════════════════════════════════════════
    // 3. Resumen Financiero — solo roles que manejan dinero
    //
    // GLOBAL del manifiesto para todos (incluidos los de bodega): el pago
    // es una obligación agrupada contra el manifiesto completo. El
    // desglose por bodega es INFORMATIVO; el sobrepago, el avance y el
    // "listo para cerrar" siguen mirando el manifiesto entero.
    // ══════════════════════════════════════════════════════════════════

    private static function resumenFinanciero(): Section
    {
        return Section::make('Resumen Financiero')
            ->icon('heroicon-o-banknotes')
            ->compact()
            ->columnSpanFull()
            ->columns(['default' => 2, 'md' => 3, 'xl' => 5])
            // Lista blanca positiva: tener 'finance' basta para verlo, aunque
            // el usuario también sea 'operador'. El operador puro no lo ve.
            ->visible(fn (): bool => Auth::user()->hasAnyRole(self::ROLES_CON_DINERO))
            ->schema([
                TextEntry::make('total_invoices')
                    ->label('Total manifiesto')
                    ->money('HNL')
                    ->size(TextSize::Large)
                    ->weight('bold')
                    ->state(fn (Manifest $record): float => (float) $record->total_invoices),

                TextEntry::make('total_returns')
                    ->label('(−) Devoluciones')
                    ->money('HNL')
                    ->size(TextSize::Large)
                    ->color('danger')
                    ->state(fn (Manifest $record): float => (float) $record->total_returns),

                TextEntry::make('total_to_deposit')
                    ->label('(=) A depositar')
                    ->money('HNL')
                    ->size(TextSize::Large)
                    ->color('warning')
                    ->weight('bold')
                    ->state(fn (Manifest $record): float => (float) $record->total_to_deposit),

                TextEntry::make('total_deposited')
                    ->label('(−) Depositado')
                    ->money('HNL')
                    ->size(TextSize::Large)
                    ->color('success')
                    ->state(fn (Manifest $record): float => (float) $record->total_deposited),

                // Solo con ajuste. SEPARADO de "Depositado" a propósito: esa
                // columna es plata real que entró al banco; el ajuste es una
                // decisión administrativa sobre centavos.
                TextEntry::make('adjustment_amount')
                    ->label('(−) Ajuste')
                    ->money('HNL')
                    ->size(TextSize::Large)
                    ->color('warning')
                    ->state(fn (Manifest $record): float => (float) $record->adjustment_amount)
                    ->visible(fn (Manifest $record): bool => (float) $record->adjustment_amount != 0.0)
                    ->tooltip('Ajuste manual de centavos registrado en la auditoría'),

                // Faltar y sobrar no se leen igual: si falta (rojo) bloquea
                // el cierre; si sobra (ámbar) se cierra con justificación.
                TextEntry::make('difference')
                    ->label(fn (Manifest $record): string => $record->isOverpaid() ? '(=) Sobrepago' : '(=) Diferencia')
                    ->money('HNL')
                    ->size(TextSize::Large)
                    ->weight('bold')
                    ->state(fn (Manifest $record): float => (float) $record->difference)
                    ->color(fn (Manifest $record): string => match (true) {
                        (float) $record->difference == 0.0 => 'success',
                        $record->isOverpaid() => 'warning',
                        default => 'danger',
                    })
                    ->icon(fn (Manifest $record): string => match (true) {
                        (float) $record->difference == 0.0 => 'heroicon-o-check-circle',
                        $record->isOverpaid() => 'heroicon-o-arrow-trending-up',
                        default => 'heroicon-o-exclamation-circle',
                    })
                    ->tooltip(fn (Manifest $record): ?string => $record->isOverpaid()
                        ? 'Se depositó de más. El manifiesto puede cerrarse dejando constancia del exceso.'
                        : null),

                TextEntry::make('deposit_progress')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->html()
                    ->state(fn (Manifest $record): HtmlString => self::barraDeposito($record)),

                self::porBodega(),

                // Los depósitos sin bodega no se le regalan a ninguna: se
                // declaran para que la suma por bodega no parezca que "pierde"
                // plata contra el total del manifiesto.
                TextEntry::make('depositos_sin_bodega')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->color('warning')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (Manifest $record): bool => $record->warehouseTotals->count() > 1
                        && self::unassignedDeposits($record) > 0)
                    ->state(fn (Manifest $record): string => 'Hay HNL '.number_format(self::unassignedDeposits($record), 2)
                        .' en depósitos sin bodega asignada. Cuentan en el total del manifiesto pero no en el '
                        .'desglose por bodega. Se corrigen editando el depósito en la pestaña Depósitos.'),
            ]);
    }

    /**
     * Avance del depósito contra lo que hay que depositar, como barra.
     */
    private static function barraDeposito(Manifest $record): HtmlString
    {
        $aDepositar = (float) $record->total_to_deposit;

        if ($aDepositar <= 0) {
            return new HtmlString('<span style="font-size:13px;opacity:.6;">Sin monto a depositar.</span>');
        }

        $porcentaje = min(100, round(((float) $record->total_deposited / $aDepositar) * 100, 1));
        $color = match (true) {
            $porcentaje >= 100 => '#22c55e',
            $porcentaje >= 50 => '#f59e0b',
            default => '#ef4444',
        };
        $texto = rtrim(rtrim(number_format($porcentaje, 1), '0'), '.');

        return new HtmlString(
            '<div style="display:flex;align-items:center;gap:12px;">'.
            '<span style="font-size:13px;opacity:.65;white-space:nowrap;">Depositado</span>'.
            '<div style="flex:1;height:8px;border-radius:999px;background:rgba(127,127,127,.22);overflow:hidden;">'.
            "<div style=\"width:{$porcentaje}%;height:100%;border-radius:999px;background:{$color};\"></div>".
            '</div>'.
            "<span style=\"font-size:13px;font-weight:700;color:{$color};white-space:nowrap;\">{$texto}%</span>".
            '</div>'
        );
    }

    /**
     * Cuánto del manifiesto le toca a cada bodega — solo en los
     * multi-bodega; en los de una sola, el resumen de arriba ya es el suyo.
     */
    private static function porBodega(): RepeatableEntry
    {
        return RepeatableEntry::make('warehouseTotals')
            ->label('Por bodega')
            ->hint('El cierre se rige por el total del manifiesto')
            ->columnSpanFull()
            ->visible(fn (Manifest $record): bool => $record->warehouseTotals->count() > 1)
            ->table([
                TableColumn::make('Bodega'),
                TableColumn::make('Total')->alignEnd(),
                TableColumn::make('(−) Devoluciones')->alignEnd(),
                TableColumn::make('(=) A depositar')->alignEnd(),
                TableColumn::make('(−) Depositado')->alignEnd(),
                TableColumn::make('(=) Diferencia')->alignEnd(),
            ])
            ->schema([
                // La bodega del usuario se pinta distinto para que
                // encuentre su fila sin leer las demás.
                TextEntry::make('warehouse.code')
                    ->badge()
                    ->color(function ($record): string {
                        /** @var User $user */
                        $user = Auth::user();

                        return in_array((int) $record->warehouse_id, $user->warehouseIds(), true)
                            ? 'primary'
                            : 'gray';
                    }),

                TextEntry::make('total_invoices')
                    ->money('HNL')
                    ->alignEnd(),

                TextEntry::make('total_returns')
                    ->money('HNL')
                    ->alignEnd()
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray'),

                TextEntry::make('total_to_deposit')
                    ->money('HNL')
                    ->alignEnd()
                    ->weight('bold')
                    ->color('warning'),

                TextEntry::make('total_deposited')
                    ->money('HNL')
                    ->alignEnd()
                    ->color('success'),

                TextEntry::make('difference')
                    ->money('HNL')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($state): string => match (true) {
                        (float) $state == 0.0 => 'success',
                        (float) $state < 0.0 => 'warning',
                        default => 'danger',
                    }),
            ]);
    }

    /**
     * Depósitos activos del manifiesto que no están imputados a ninguna bodega.
     *
     * Son los anteriores a la columna `deposits.warehouse_id` (27/08/2026) y
     * los que el backfill no pudo atribuir sin adivinar. Se memoiza porque el
     * infolist consulta esto dos veces por render: una para decidir si muestra
     * el aviso y otra para escribir el monto.
     */
    private static function unassignedDeposits($record): float
    {
        static $cache = [];

        return $cache[$record->id] ??= (float) $record->deposits()
            ->active()
            ->whereNull('warehouse_id')
            ->sum('amount');
    }
}
