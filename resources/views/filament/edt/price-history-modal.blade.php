{{-- Historial de precios en el modal del listado: la misma tabla de la pantalla de edición. --}}
<div>
    @livewire(
        \App\Filament\Resources\Edt\Products\RelationManagers\PriceHistoryRelationManager::class,
        [
            'ownerRecord' => $record,
            'pageClass' => \App\Filament\Resources\Edt\Products\Pages\EditEdtProduct::class,
        ],
        key('edt-price-history-'.$record->getKey())
    )
</div>
