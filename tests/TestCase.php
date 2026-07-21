<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La ventana de registro de devoluciones (N días hábiles desde la
        // llegada del manifiesto) se DESACTIVA por default en tests: las
        // factories crean manifiestos con fechas aleatorias del pasado y el
        // candado de ReturnService haría flaky cualquier suite que registre
        // devoluciones. Las suites que SÍ prueban la ventana fijan su propio
        // valor o deadline explícito:
        //   - ReturnServiceVentanaDevolucionesTest (config = 5)
        //   - DevolucionesControllerVentanaTest (returns_deadline_at directo)
        config(['api.devoluciones_ventana_dias_habiles' => 10000]);
    }
}
