<?php

namespace App\Models\Edt\Concerns;

/**
 * Guarda y borra dentro de una transacción.
 *
 * Los observers del EDT escriben el historial de precios DURANTE el save
 * (producto, descuento del proveedor, escalas). Con esto, si el historial
 * falla, el cambio del modelo también se revierte: nunca queda un precio
 * cambiado sin su fila de historial, venga el cambio de Filament, de un
 * comando o de tinker. Dentro de otra transacción se anida como savepoint.
 */
trait SavesAtomically
{
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn (): ?bool => parent::delete());
    }
}
