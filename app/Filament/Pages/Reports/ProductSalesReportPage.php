<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Exports\ProductSalesExport;
use App\Jobs\NotifyExportReady;
use App\Models\User;
use App\Services\Reports\ProductSalesFilters;
use App\Services\Reports\ProductSalesReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Reporte de ventas por producto (menú "Reporte de ventas").
 *
 * La pantalla solo arma filtros y pinta: los números salen de
 * ProductSalesReportService, el mismo que usan la impresión y el Excel.
 *
 * Acceso: permiso ReportProductSales:Invoice (admin y finanzas; super_admin
 * lo recibe explícito porque no usa Gate::before). Un usuario con bodegas
 * asignadas solo consulta las suyas, sin importar lo que mande el navegador.
 */
class ProductSalesReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected string $view = 'filament.pages.reports.product-sales-report';

    protected static ?string $navigationLabel = 'Reporte de ventas';

    protected static ?string $title = 'Reporte de ventas por producto';

    protected static ?string $slug = 'reportes/ventas-por-producto';

    protected static ?int $navigationSort = 2;

    /**
     * Estado del formulario de filtros.
     *
     * @var array<string, mixed>|null
     */
    public ?array $filters = [];

    /**
     * Filtros del último reporte generado, ya validados y con las bodegas
     * resueltas contra el usuario. Bloqueado: el navegador no puede cambiarlo.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $applied = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can(ProductSalesReportService::PERMISSION) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'period' => 'this_month',
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'date_field' => ProductSalesFilters::DATE_FIELD_INVOICE,
            'warehouse_ids' => [],
            'sort' => ProductSalesReportService::SORT_NET_AMOUNT,
        ]);

        // Al entrar ya se ve el mes en curso, sin tener que darle a Generar.
        $this->generate();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->components([
                Select::make('period')
                    ->label('Período')
                    ->options([
                        'this_month' => 'Este mes',
                        'last_month' => 'Mes anterior',
                        'custom' => 'Rango personalizado',
                    ])
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => self::fillPeriodDates($state, $set)),

                DatePicker::make('date_from')
                    ->label('Desde')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('period', 'custom')),

                DatePicker::make('date_to')
                    ->label('Hasta')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('period', 'custom')),

                Select::make('date_field')
                    ->label('Contar por')
                    ->options([
                        ProductSalesFilters::DATE_FIELD_INVOICE => 'Fecha de factura',
                        ProductSalesFilters::DATE_FIELD_MANIFEST => 'Fecha del manifiesto (día de carga)',
                    ])
                    ->selectablePlaceholder(false)
                    ->required(),

                Select::make('warehouse_ids')
                    ->label('Bodega')
                    ->multiple()
                    ->options(fn (): array => app(ProductSalesReportService::class)->warehouseOptions($this->user()))
                    ->placeholder('Todas mis bodegas'),

                Select::make('sort')
                    ->label('Ordenar por')
                    ->options(ProductSalesReportService::sortOptions())
                    ->selectablePlaceholder(false)
                    // El orden se aplica al momento sobre el reporte ya
                    // generado: no vuelve a consultar la base.
                    ->live(),
            ]);
    }

    public function generate(): void
    {
        $state = $this->form->getState();

        try {
            $filters = ProductSalesFilters::fromArray([
                'date_from' => $state['date_from'] ?? null,
                'date_to' => $state['date_to'] ?? null,
                'date_field' => $state['date_field'] ?? null,
                'warehouse_ids' => app(ProductSalesReportService::class)
                    ->resolveWarehouseIds($this->user(), (array) ($state['warehouse_ids'] ?? [])),
            ]);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('No se pudo generar el reporte')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->applied = $filters->toArray();
        unset($this->report);
    }

    /**
     * El reporte del último "Generar", ordenado según el selector.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function report(): ?array
    {
        $filters = $this->appliedFilters();

        if ($filters === null) {
            return null;
        }

        $report = app(ProductSalesReportService::class)->generateCached($filters);
        $report['rows'] = ProductSalesReportService::sortRows($report['rows'], $this->currentSort());

        return $report;
    }

    /**
     * Enlace cifrado a la vista de impresión con los mismos filtros (y el
     * mismo orden) que se están viendo.
     */
    public function printUrl(): ?string
    {
        if ($this->applied === null) {
            return null;
        }

        $payload = Crypt::encryptString((string) json_encode([
            ...$this->applied,
            'sort' => $this->currentSort(),
        ]));

        return route('reports.product-sales', ['payload' => $payload]);
    }

    /**
     * Manda el Excel a la cola `reports` con el reporte que ya está en caché
     * (no vuelve a consultar la base) y avisa al usuario cuando está listo,
     * igual que los demás exports del sistema.
     */
    public function exportExcel(): void
    {
        abort_unless(static::canAccess(), 403);

        $report = $this->report;

        if ($report === null) {
            return;
        }

        // La hora en el nombre evita que dos exportaciones del mismo período
        // se pisen el archivo en storage/app/exports/ antes de descargarse.
        $fileName = 'ventas_por_producto_'.$report['filters']['date_from'].'_al_'.$report['filters']['date_to']
            .'_'.now()->format('His').'.xlsx';
        $filePath = "exports/{$fileName}";

        (new ProductSalesExport($report))->queue($filePath, 'local')->chain([
            (new NotifyExportReady(
                userId: (int) Auth::id(),
                filePath: $filePath,
                fileName: $fileName,
            ))->onQueue('high'),
        ]);

        Notification::make()
            ->title('Exportación en proceso')
            ->body("El archivo {$fileName} se está generando. Te notificaremos cuando esté listo.")
            ->info()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Imprimir')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): ?string => $this->printUrl(), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $this->applied !== null),

            Action::make('export')
                ->label('Exportar Excel')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->visible(fn (): bool => $this->applied !== null)
                ->action(fn () => $this->exportExcel()),
        ];
    }

    private static function fillPeriodDates(?string $period, Set $set): void
    {
        [$from, $to] = match ($period) {
            'this_month' => [now()->startOfMonth(), now()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            default => [null, null],
        };

        if ($from === null || $to === null) {
            return;
        }

        $set('date_from', $from->toDateString());
        $set('date_to', $to->toDateString());
    }

    private function appliedFilters(): ?ProductSalesFilters
    {
        if ($this->applied === null) {
            return null;
        }

        try {
            // Las bodegas se vuelven a cruzar con el usuario en cada carga:
            // si le quitan una bodega a media sesión, deja de verla.
            return ProductSalesFilters::fromArray([
                ...$this->applied,
                'warehouse_ids' => app(ProductSalesReportService::class)
                    ->resolveWarehouseIds($this->user(), (array) ($this->applied['warehouse_ids'] ?? [])),
            ]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function currentSort(): string
    {
        $sort = (string) ($this->filters['sort'] ?? '');

        return array_key_exists($sort, ProductSalesReportService::sortOptions())
            ? $sort
            : ProductSalesReportService::SORT_NET_AMOUNT;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
