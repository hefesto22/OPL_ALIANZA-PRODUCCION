<?php

namespace App\Filament\Resources\Returns\Pages;

use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\Returns\Schemas\ReturnForm;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Services\ReturnService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditReturn extends EditRecord
{
    protected static string $resource = ReturnResource::class;

    protected static ?string $title = 'Editar Devolución';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Guard 1: manifiesto cerrado.
        if ($this->record->manifest->isClosed()) {
            Notification::make()
                ->title('Manifiesto cerrado')
                ->body('No se puede editar una devolución de un manifiesto cerrado.')
                ->warning()
                ->send();

            $this->redirect(
                $this->getResource()::getUrl('view', ['record' => $this->record])
            );
            return;
        }

        // Guard 2: ventana de edición del día.
        // Las devoluciones solo pueden editarse el mismo día calendario en que
        // fueron creadas. Después de medianoche Jaremar puede haberlas consumido
        // vía API, por lo que cualquier cambio posterior crearía inconsistencia.
        if (! $this->record->isEditableToday()) {
            Notification::make()
                ->title('Devolución bloqueada')
                ->body(
                    'Esta devolución fue registrada el ' .
                    $this->record->created_at->format('d/m/Y') .
                    ' y ya no puede modificarse. Si necesitas correcciones, crea una nueva devolución.'
                )
                ->warning()
                ->send();

            $this->redirect(
                $this->getResource()::getUrl('view', ['record' => $this->record])
            );
        }
    }

    public function form(Schema $schema): Schema
    {
        return ReturnForm::make($schema, editing: true);
    }

    /**
     * Pre-carga el formulario con TODAS las líneas de la factura, no solo
     * las que tiene esta devolución.
     *
     * Lógica de "cantidad disponible":
     *   disponible = qty_factura - devuelto_por_OTRAS_devoluciones
     *
     * Excluimos esta devolución del cálculo para que el usuario vea
     * la cantidad que YA reservó (no quede bloqueado en máx = 0).
     *
     * Lógica de "cantidad pre-rellena":
     *   Si esta devolución ya tenía esa línea → mostramos esa cantidad.
     *   Si no → 0 (producto "olvidado" que el usuario puede agregar ahora).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var InvoiceReturn $record */
        $record = $this->record;

        $invoice = Invoice::with(['lines', 'manifest'])->find($record->invoice_id);
        if (! $invoice) {
            return $data;
        }

        $returnService = app(ReturnService::class);
        $lineIds       = $invoice->lines->pluck('id')->toArray();

        // Cantidades devueltas por OTRAS devoluciones (excluye la actual).
        $returnedByOthers = $returnService->getReturnedQuantitiesForLinesExcluding(
            $lineIds,
            $record->id
        );

        // Líneas actuales de esta devolución, indexadas por invoice_line_id.
        $existingLines = $record->lines->keyBy('invoice_line_id');

        // Solo cargamos las líneas que pertenecen a ESTA devolución.
        // Si el usuario necesita devolver un producto adicional de la misma
        // factura, debe crear una nueva devolución (mejor trazabilidad).
        $lines = $invoice->lines
            ->filter(fn ($line) => $existingLines->has($line->id))
            ->map(function ($line) use ($returnedByOthers, $existingLines) {
                $otherReturnsQty = (float) ($returnedByOthers[$line->id] ?? 0);
                $available       = max(0, (float) $line->quantity_fractions - $otherReturnsQty);
                // Null → usar price; 0 → bonificación (gratis). No usar ?: porque 0 es falsy.
                $unitPrice       = $line->price_min_sale !== null ? (float) $line->price_min_sale : (float) $line->price;
                $convFactor      = max(1, (float) ($line->conversion_factor ?? 1));
                $availableBoxes  = (int) floor($available / $convFactor);
                $pricePerBox     = round($convFactor * $unitPrice, 2);

                $existingLine = $existingLines->get($line->id);
                $qtyBox       = (float) $existingLine->quantity_box;
                $qty          = (float) $existingLine->quantity;
                $lineTotal    = (float) $existingLine->line_total;

                return [
                    'invoice_line_id'     => $line->id,
                    'line_number'         => $line->line_number,
                    'product_id'          => $line->product_id,
                    'product_description' => $line->product_description,
                    'unit_sale'           => strtoupper($line->unit_sale ?? 'UN'),
                    'quantity_box'        => $qtyBox,
                    'quantity'            => $qty,
                    'available_quantity'  => $available,
                    'available_boxes'     => $availableBoxes,
                    'conversion_factor'   => $convFactor,
                    'unit_price'          => $unitPrice,
                    'price_per_box'       => $pricePerBox,
                    'line_total'          => $lineTotal,
                ];
            })->values()->toArray();

        // Disponible = total factura − lo devuelto por OTRAS devoluciones (excluye la actual)
        $otherReturnsTotal = $invoice->returns()
            ->where('id', '!=', $record->id)
            ->whereIn('status', ['approved', 'pending'])
            ->sum('total');
        $availableTotal = max(0, round((float) $invoice->total - (float) $otherReturnsTotal, 2));

        $data['lines']            = $lines;
        $data['client_name']      = $invoice->client_name;
        $data['invoice_number']   = $invoice->invoice_number;
        $data['invoice_total']    = round((float) $invoice->total, 2);
        $data['available_total']  = $availableTotal;
        $data['manifest_number']  = $invoice->manifest?->number ?? '';

        return $data;
    }

    /**
     * Delega toda la lógica de edición al ReturnService.
     *
     * La página Filament mantiene solo responsabilidades de UI:
     *   - Pre-checks con notificaciones "suaves" (mejor UX que una
     *     ValidationException genérica, pero no son la fuente de verdad).
     *   - La ValidationException del servicio burbujea y Filament la
     *     bindea automáticamente a los campos del formulario.
     *
     * La fuente de verdad de los guards (manifiesto cerrado, ventana de
     * edición, cantidades disponibles) vive en ReturnService::updateReturn,
     * que es lo que tests y futuros endpoints usan directamente.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var InvoiceReturn $record */

        // Pre-checks de UX: notificación amigable + halt. El servicio
        // también tiene estos guards como defensa final, pero fallar
        // acá nos permite mostrar una notificación de Filament en vez
        // de una ValidationException genérica con mensaje suelto.
        if ($record->manifest->isClosed()) {
            Notification::make()
                ->title('Manifiesto cerrado')
                ->body('El manifiesto fue cerrado mientras editabas. Los cambios no fueron guardados.')
                ->danger()
                ->send();

            $this->halt();
        }

        if (! $record->isEditableToday()) {
            Notification::make()
                ->title('Ventana de edición expirada')
                ->body('La devolución solo puede editarse el día en que fue registrada.')
                ->danger()
                ->send();

            $this->halt();
        }

        // Delegamos al servicio. Los errores de validación por línea
        // (p.ej. "lines.42.quantity") los bindea Filament automáticamente
        // al Repeater correspondiente.
        return app(ReturnService::class)->updateReturn($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            DeleteAction::make()
                ->hidden(fn (): bool =>
                    $this->record->manifest->isClosed() ||
                    ! $this->record->isEditableToday()
                ),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
