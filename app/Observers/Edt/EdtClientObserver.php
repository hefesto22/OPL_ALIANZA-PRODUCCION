<?php

namespace App\Observers\Edt;

use App\Models\Edt\EdtClient;
use App\Services\Edt\EdtClientCodeGenerator;

/**
 * Reglas automáticas del cliente del EDT al guardar.
 */
class EdtClientObserver
{
    public function __construct(private readonly EdtClientCodeGenerator $codes) {}

    /**
     * Sin crédito no hay límite ni plazo: se limpian aquí para que apagar el
     * interruptor en el formulario no choque con el CHECK de la tabla.
     */
    public function saving(EdtClient $client): void
    {
        if (! $client->credit_enabled) {
            $client->credit_limit = null;
            $client->credit_days = null;
        }
    }

    /**
     * Código vacío = el siguiente automático (C-000001…). El cast Uppercase
     * ya convirtió "" o "   " en null.
     */
    public function creating(EdtClient $client): void
    {
        if (blank($client->code)) {
            $client->code = $this->codes->next();
        }
    }
}
