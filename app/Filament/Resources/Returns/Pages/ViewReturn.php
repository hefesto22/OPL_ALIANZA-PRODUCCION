<?php

namespace App\Filament\Resources\Returns\Pages;

use App\Filament\Resources\Returns\ReturnResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class ViewReturn extends ViewRecord
{
    protected static string $resource = ReturnResource::class;

    protected static ?string $title = 'Ver Devolución';

    protected function getHeaderActions(): array
    {
        return [
            // Editar — disponible solo el mismo día del registro.
            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => ! $this->record->isCancelled() &&
                    ! $this->record->manifest->isClosed() &&
                    $this->record->isEditableToday()
                )
                ->tooltip(fn (): string => $this->record->getEditabilityLabel()),

            // Bloqueada — muestra por qué no se puede editar al día siguiente.
            Action::make('locked')
                ->label($this->record->getEditabilityLabel())
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->disabled()
                ->visible(fn (): bool => ! $this->record->manifest->isClosed() &&
                    ! $this->record->isEditableToday()
                ),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([

            // ── Fila 1: Datos Generales ───────────────────────────────────
            Section::make('Datos Generales')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Grid::make(4)->schema([

                        TextEntry::make('invoice.invoice_number')
                            ->label('# Factura')
                            ->weight(FontWeight::Bold)
                            ->copyable()
                            ->copyMessage('Número copiado')
                            ->fontFamily('mono'),

                        TextEntry::make('manifest.number')
                            ->label('# Manifiesto')
                            ->fontFamily('mono'),

                        TextEntry::make('client_name')
                            ->label('Cliente')
                            ->weight(FontWeight::SemiBold),

                        TextEntry::make('warehouse.code')
                            ->label('Bodega')
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                'OAC' => 'info',
                                'OAO' => 'success',
                                'OAS' => 'warning',
                                default => 'gray',
                            }),

                        TextEntry::make('returnReason.description')
                            ->label('Motivo de Devolución')
                            ->columnSpan(2),

                        TextEntry::make('return_date')
                            ->label('Fecha')
                            ->date('d/m/Y')
                            ->icon('heroicon-m-calendar-days'),

                        TextEntry::make('notes')
                            ->label('Observaciones')
                            ->placeholder('—')
                            ->columnSpan(2),
                    ]),
                ]),

            // ── Fila 2: Resumen ───────────────────────────────────────────
            Section::make('Resumen')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Grid::make(5)->schema([

                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                'cancelled' => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'pending' => 'Pendiente',
                                'approved' => 'Aprobada',
                                'rejected' => 'Rechazada',
                                'cancelled' => 'Cancelada',
                                default => $state,
                            })
                            ->icon(fn ($state) => match ($state) {
                                'pending' => 'heroicon-m-clock',
                                'approved' => 'heroicon-m-check-circle',
                                'rejected' => 'heroicon-m-x-circle',
                                'cancelled' => 'heroicon-m-no-symbol',
                                default => null,
                            }),

                        TextEntry::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'total' => 'Total',
                                'partial' => 'Parcial',
                                default => $state,
                            })
                            ->color(fn ($state) => match ($state) {
                                'total' => 'danger',
                                'partial' => 'warning',
                                default => 'gray',
                            }),

                        TextEntry::make('total')
                            ->label('Total Devuelto')
                            ->money('HNL')
                            ->weight(FontWeight::Bold)
                            ->color('danger'),

                        TextEntry::make('lines_count')
                            ->label('Líneas')
                            ->state(fn ($record) => $record->lines->count())
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('lines_qty')
                            ->label('Unidades')
                            ->state(fn ($record) => number_format($record->lines->sum('quantity'), 4))
                            ->fontFamily('mono'),
                    ]),
                ]),

            // ── Fila 3: Auditoría ─────────────────────────────────────────
            Section::make('Auditoría')
                ->icon('heroicon-o-shield-check')
                ->schema([
                    Grid::make(4)->schema([

                        TextEntry::make('createdBy.name')
                            ->label('Registrado por')
                            ->icon('heroicon-m-user'),

                        TextEntry::make('created_at')
                            ->label('Fecha de Registro')
                            ->dateTime('d/m/Y H:i')
                            ->icon('heroicon-m-calendar'),

                        TextEntry::make('reviewed_by_label')
                            ->label('Revisado por')
                            ->icon('heroicon-m-check-badge')
                            ->state(function ($record): string {
                                // Si ya hay un revisor humano registrado, mostrarlo.
                                if ($record->reviewed_by && $record->reviewedBy) {
                                    return $record->reviewedBy->name;
                                }
                                // Si la ventana de edición ya cerró, el sistema fue el revisor.
                                if (! $record->isEditableToday()) {
                                    return 'Sistema';
                                }

                                return 'Sin revisar';
                            })
                            ->color(fn ($state) => $state === 'Sistema' ? 'info' : null)
                            ->badge(fn ($state) => $state === 'Sistema'),

                        TextEntry::make('reviewed_at_label')
                            ->label('Fecha de Revisión')
                            ->icon('heroicon-m-calendar-days')
                            ->state(function ($record): ?string {
                                // Si hay fecha de revisión humana, usarla.
                                if ($record->reviewed_at) {
                                    return $record->reviewed_at->format('d/m/Y H:i');
                                }
                                // Si la ventana cerró, el sistema "revisó" al final de ese día.
                                if (! $record->isEditableToday()) {
                                    return $record->created_at->endOfDay()->format('d/m/Y H:i');
                                }

                                return null;
                            })
                            ->placeholder('—'),
                    ]),
                ]),

            // ── Motivo de Rechazo (condicional) ───────────────────────────
            Section::make('Motivo de Rechazo')
                ->icon('heroicon-o-exclamation-triangle')
                ->collapsible()
                ->visible(fn () => $this->record->isRejected())
                ->schema([
                    TextEntry::make('rejection_reason')
                        ->label('')
                        ->color('danger')
                        ->icon('heroicon-m-chat-bubble-left-ellipsis'),
                ]),

            // ── Cancelación (condicional) ────────────────────────────────
            Section::make('Información de Cancelación')
                ->icon('heroicon-o-no-symbol')
                ->collapsible()
                ->visible(fn () => $this->record->isCancelled())
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('cancellation_reason')
                            ->label('Motivo')
                            ->icon('heroicon-m-chat-bubble-left-ellipsis')
                            ->color('gray')
                            ->columnSpan(2),

                        TextEntry::make('cancelledBy.name')
                            ->label('Cancelado por')
                            ->icon('heroicon-m-user')
                            ->placeholder('—'),

                        TextEntry::make('cancelled_at')
                            ->label('Fecha de cancelación')
                            ->dateTime('d/m/Y H:i')
                            ->icon('heroicon-m-calendar')
                            ->placeholder('—'),
                    ]),
                ]),

            // ── Fila 4: Líneas Devueltas ──────────────────────────────────
            Section::make('Líneas Devueltas')
                ->icon('heroicon-o-list-bullet')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label('')
                        ->columns(6)
                        ->schema([
                            TextEntry::make('line_number')
                                ->label('#')
                                ->badge()
                                ->color('gray')
                                ->columnSpan(1),

                            TextEntry::make('product_id')
                                ->label('Código')
                                ->fontFamily('mono')
                                ->copyable()
                                ->columnSpan(1),

                            TextEntry::make('product_description')
                                ->label('Descripción')
                                ->columnSpan(2),

                            TextEntry::make('quantity')
                                ->label(fn ($record) => $record->quantity_box > 0 ? 'Cajas' : 'Unidades')
                                ->fontFamily('mono')
                                ->state(fn ($record) => $record->quantity_box > 0
                                    ? number_format($record->quantity_box, 0).' cja.'
                                    : number_format($record->quantity, 2).' und.'
                                )
                                ->alignStart(),

                            TextEntry::make('line_total')
                                ->label('Total')
                                ->money('HNL')
                                ->weight(FontWeight::Bold)
                                ->color('danger'),
                        ]),
                ]),
        ]);
    }
}
