<?php

namespace Tests\Unit\Services;

use App\Services\ApiInvoiceImporterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests unitarios sobre validateWarehouses().
 *
 * validateWarehouses() es la puerta de entrada: si una factura viene con
 * un almacén desconocido, NO se debe importar silenciosamente — hay que
 * reportarla como conflicto para que el operador la revise. Este test
 * blinda ese comportamiento inyectando un warehouseMap por reflexión
 * (sin tocar la base de datos).
 *
 * Bodegas del sistema (ver project_warehouses memory):
 *   - OAC → Copán
 *   - OAS → Santa Bárbara
 *   - OAO → Ocotepeque
 */
class ApiInvoiceImporterServiceValidateWarehousesTest extends TestCase
{
    private function makeService(): ApiInvoiceImporterService
    {
        $service = (new ReflectionClass(ApiInvoiceImporterService::class))
            ->newInstanceWithoutConstructor();

        // Inyectamos el warehouseMap directamente para no depender de BD.
        $prop = (new ReflectionClass(ApiInvoiceImporterService::class))
            ->getProperty('warehouseMap');
        $prop->setAccessible(true);
        $prop->setValue($service, [
            'OAC' => 1,
            'OAS' => 2,
            'OAO' => 3,
        ]);

        return $service;
    }

    private function invokeValidate(ApiInvoiceImporterService $svc, array $invoices): array
    {
        $method = (new ReflectionClass(ApiInvoiceImporterService::class))
            ->getMethod('validateWarehouses');
        $method->setAccessible(true);

        return $method->invoke($svc, $invoices);
    }

    public function test_all_valid_warehouses_returns_no_errors(): void
    {
        $errors = $this->invokeValidate($this->makeService(), [
            ['Nfactura' => 'F001', 'Almacen' => 'OAC'],
            ['Nfactura' => 'F002', 'Almacen' => 'OAS'],
            ['Nfactura' => 'F003', 'Almacen' => 'OAO'],
        ]);

        $this->assertSame([], $errors);
    }

    public function test_unknown_warehouse_is_reported_with_invoice_number(): void
    {
        $errors = $this->invokeValidate($this->makeService(), [
            ['Nfactura' => 'F001', 'Almacen' => 'XXX'],
            ['Nfactura' => 'F002', 'Almacen' => 'OAC'],
        ]);

        $this->assertArrayHasKey('XXX', $errors);
        $this->assertSame(['F001'], $errors['XXX']);
        // La válida no debe aparecer en errores
        $this->assertArrayNotHasKey('OAC', $errors);
    }

    public function test_empty_warehouse_is_grouped_under_vacio_marker(): void
    {
        $errors = $this->invokeValidate($this->makeService(), [
            ['Nfactura' => 'F001', 'Almacen' => ''],
            ['Nfactura' => 'F002'],           // campo ausente
            ['Nfactura' => 'F003', 'Almacen' => null],
        ]);

        $this->assertArrayHasKey('(vacío)', $errors);
        $this->assertSame(['F001', 'F002', 'F003'], $errors['(vacío)']);
    }

    public function test_multiple_errors_of_same_unknown_warehouse_are_grouped(): void
    {
        $errors = $this->invokeValidate($this->makeService(), [
            ['Nfactura' => 'F001', 'Almacen' => 'ZZZ'],
            ['Nfactura' => 'F002', 'Almacen' => 'ZZZ'],
            ['Nfactura' => 'F003', 'Almacen' => 'YYY'],
        ]);

        $this->assertSame(['F001', 'F002'], $errors['ZZZ']);
        $this->assertSame(['F003'], $errors['YYY']);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->invokeValidate($this->makeService(), []));
    }
}
