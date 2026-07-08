<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Manifest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ManifestPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Manifest');
    }

    public function view(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('View:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Manifest');
    }

    public function update(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Update:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    public function delete(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Delete:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    public function restore(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Restore:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    public function forceDelete(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ForceDelete:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Manifest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Manifest');
    }

    public function replicate(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Replicate:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Manifest');
    }

    /**
     * Cerrar un manifiesto (acción de negocio, permiso custom).
     *
     * Permiso: Close:Manifest (ver CustomPermissionSeeder). La condición
     * de estado (isReadyToClose) la valida la UI/Service — la Policy solo
     * autoriza al actor y lo restringe a SU bodega vía userOwnsManifest.
     */
    public function close(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Close:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * Reabrir un manifiesto cerrado (acción sensible, permiso custom).
     *
     * Permiso: Reopen:Manifest. Por matriz solo lo tiene admin (+ super_admin
     * vía gate). userOwnsManifest deja pasar a usuarios globales.
     */
    public function reopen(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Reopen:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * Ver la pestaña "Depósitos" dentro de la vista del manifiesto.
     *
     * Permiso: ViewDeposits:Manifest (custom, ver CustomPermissionSeeder).
     * Antes era `! hasRole('operador')` inline en el RelationManager — un
     * blacklist que rompía con usuarios multi-rol: operador+finance perdía
     * la pestaña aunque finance debía verla. Qué depósitos ve DENTRO de la
     * pestaña lo sigue filtrando Deposit::visibleTo (jerarquía created_by).
     */
    public function viewDeposits(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ViewDeposits:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * Ver la pestaña "Devoluciones" dentro de la vista del manifiesto.
     *
     * Permiso: ViewReturns:Manifest (custom). Independiente de
     * ViewAny:InvoiceReturn (navegación al recurso Devoluciones): el
     * operador conserva el recurso para capturar devoluciones, pero la
     * pestaña financiera del manifiesto se asigna por separado.
     */
    public function viewReturns(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ViewReturns:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * Botón "Reporte PDF" (reporte de facturas del manifiesto).
     */
    public function exportInvoicesPdf(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ExportInvoicesPdf:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * Botón "Sublista Productos" (reporte de productos por bodega).
     */
    public function exportProductsPdf(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ExportProductsPdf:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * Botón "Sublista Facturas" (checklist de facturas).
     */
    public function exportChecklistPdf(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ExportChecklistPdf:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * Botón "Devoluciones" (reporte PDF de devoluciones del manifiesto).
     */
    public function exportReturnsPdf(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ExportReturnsPdf:Manifest')
            && $this->userOwnsManifest($authUser, $manifest);
    }

    /**
     * ¿El usuario tiene acceso a este manifiesto según su bodega?
     *
     * Un manifiesto NO tiene una sola bodega: puede abarcar varias (las
     * facturas que contiene pueden ser de distintas bodegas), por eso su
     * columna `warehouse_id` suele ser NULL cuando entra por la API
     * (ApiInvoiceImporterService crea el manifiesto con warehouse_id = null
     * y cada factura lleva su propia bodega).
     *
     * La pertenencia se decide igual que el scope del listado
     * (ManifestResource::getEloquentQuery): un usuario de bodega "posee" el
     * manifiesto si éste tiene al menos una factura de SU bodega. Antes se
     * comparaba manifest.warehouse_id contra user.warehouse_id, lo que daba
     * 403 al abrir cualquier manifiesto importado por API (warehouse_id null).
     *
     * Se cubren ambos casos:
     *   - Manifiesto con warehouse_id directo (demo/seeders) → comparación directa.
     *   - Manifiesto multi-bodega (API, warehouse_id null) → vía sus facturas.
     */
    private function userOwnsManifest(AuthUser $authUser, Manifest $manifest): bool
    {
        /** @var User $authUser */
        $warehouseIds = $authUser->warehouseIds();

        // Usuario global (sin bodegas) → ve cualquier manifiesto.
        if ($warehouseIds === []) {
            return true;
        }

        // Caso 1: el manifiesto tiene bodega propia (datos demo/manuales).
        if ($manifest->warehouse_id !== null) {
            return in_array((int) $manifest->warehouse_id, $warehouseIds, true);
        }

        // Caso 2: manifiesto multi-bodega (API). Pertenece si tiene alguna
        // factura de UNA de las bodegas del usuario — misma lógica que el listado.
        return $manifest->invoices()
            ->whereIn('warehouse_id', $warehouseIds)
            ->exists();
    }
}
