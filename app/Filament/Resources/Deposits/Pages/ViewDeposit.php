<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\Deposit;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class ViewDeposit extends ViewRecord
{
    protected static string $resource = DepositResource::class;

    protected static ?string $title = 'Ver Depósito';

    protected function getHeaderActions(): array
    {
        return [
            // Editar oculto en cancelados (la Policy también bloquea, esto
            // evita mostrar el botón en la UI).
            EditAction::make()
                ->hidden(fn (): bool => $this->record->manifest->isClosed()
                    || $this->record->isCancelled()
                ),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([

            Section::make('Datos del Depósito')
                ->icon('heroicon-o-banknotes')
                ->columns(4)
                ->schema([
                    TextEntry::make('manifest.number')
                        ->label('# Manifiesto')
                        ->weight(FontWeight::Bold)
                        ->url(fn () => ManifestResource::getUrl('view', ['record' => $this->record->manifest_id]))
                        ->color('primary'),

                    TextEntry::make('manifest.status')
                        ->label('Estado Manifiesto')
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'pending' => 'gray',
                            'processing' => 'warning',
                            'imported' => 'info',
                            'closed' => 'success',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'pending' => 'Pendiente',
                            'processing' => 'Procesando',
                            'imported' => 'Importado',
                            'closed' => 'Cerrado',
                            default => $state,
                        }),

                    // Estado del depósito: Activo / Cancelado. Hace explícita
                    // la diferencia para que el lector no tenga que adivinar
                    // por la ausencia/presencia de la sección de cancelación.
                    TextEntry::make('estado_deposito')
                        ->label('Estado Depósito')
                        ->badge()
                        ->getStateUsing(fn (Deposit $record): string => $record->isCancelled() ? 'cancelado' : 'activo')
                        ->color(fn (string $state): string => $state === 'cancelado' ? 'danger' : 'success')
                        ->icon(fn (string $state): string => $state === 'cancelado'
                            ? 'heroicon-o-x-circle'
                            : 'heroicon-o-check-circle'
                        )
                        ->formatStateUsing(fn (string $state): string => $state === 'cancelado' ? 'Cancelado' : 'Activo'),

                    TextEntry::make('deposit_date')
                        ->label('Fecha de Depósito')
                        ->date('d/m/Y')
                        ->icon('heroicon-m-calendar-days'),

                    TextEntry::make('amount')
                        ->label('Monto')
                        ->money('HNL')
                        ->weight(FontWeight::Bold)
                        ->color('success'),

                    TextEntry::make('bank')
                        ->label('Banco')
                        ->placeholder('—'),

                    TextEntry::make('reference')
                        ->label('Referencia / No. Boleta')
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->copyable(),

                    TextEntry::make('observations')
                        ->label('Observaciones')
                        ->placeholder('—')
                        ->columnSpan(4),
                ]),

            // ── Comprobante ────────────────────────────────────────────
            // Visible solo cuando hay archivo asociado. Como al cancelar el
            // depósito el archivo se borra y receipt_image queda en null,
            // la sección entera desaparece para cancelados — consistente
            // con la regla "los cancelados no muestran la imagen".
            Section::make('Comprobante')
                ->icon('heroicon-o-photo')
                ->visible(fn (Deposit $record): bool => $record->receipt_image !== null)
                ->schema([
                    ImageEntry::make('receipt_image')
                        ->label('')
                        ->hiddenLabel()
                        // Usar el accessor receipt_url del modelo: genera
                        // signed URL con TTL 30min vía el controller seguro.
                        // No exponemos paths del disk local directamente.
                        ->getStateUsing(fn (Deposit $record): ?string => $record->receipt_url)
                        ->height(400)
                        ->extraImgAttributes(['class' => 'rounded-lg'])
                        ->columnSpanFull(),
                ]),

            // ── Cancelación ─────────────────────────────────────────────
            // Solo visible cuando el depósito está cancelado. Documenta
            // quién canceló, cuándo y por qué — la información que justifica
            // por qué este depósito ya no cuenta en los totales del manifest.
            Section::make('Cancelación')
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->description('Este depósito fue cancelado y no cuenta en los totales del manifiesto.')
                ->visible(fn (Deposit $record): bool => $record->isCancelled())
                ->columns(2)
                ->schema([
                    TextEntry::make('cancellation_reason')
                        ->label('Motivo de la cancelación')
                        ->columnSpanFull()
                        ->placeholder('—'),

                    TextEntry::make('cancelledBy.name')
                        ->label('Cancelado por')
                        ->icon('heroicon-m-user')
                        ->placeholder('—'),

                    TextEntry::make('cancelled_at')
                        ->label('Fecha de cancelación')
                        ->dateTime('d/m/Y H:i')
                        ->icon('heroicon-m-calendar'),
                ]),

            Section::make('Auditoría')
                ->icon('heroicon-o-shield-check')
                ->columns(2)
                ->schema([
                    TextEntry::make('createdBy.name')
                        ->label('Registrado por')
                        ->icon('heroicon-m-user'),

                    TextEntry::make('created_at')
                        ->label('Fecha de Registro')
                        ->dateTime('d/m/Y H:i')
                        ->icon('heroicon-m-calendar'),
                ]),
        ]);
    }
}
