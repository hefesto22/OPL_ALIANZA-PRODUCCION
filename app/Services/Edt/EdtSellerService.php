<?php

namespace App\Services\Edt;

use App\Casts\Uppercase;
use App\Models\Edt\EdtSeller;
use App\Models\User;
use App\Support\Edt\EdtModule;
use Illuminate\Support\Facades\DB;

/**
 * Alta y cambios de vendedores del EDT junto con su usuario.
 *
 * El vendedor y su usuario se guardan en la MISMA transacción: nunca queda
 * un usuario con rol de vendedor sin ficha (vería clientes sin filtro de
 * zona) ni una ficha sin usuario. El usuario:
 *   - lleva el mismo nombre (en MAYÚSCULAS) y el estado activo del vendedor;
 *   - queda amarrado a la bodega del vendedor (user_warehouse), así
 *     WarehouseScope lo limita a su bodega;
 *   - tiene el rol vendedor_edt (lo crea EdtPermissionSeeder).
 */
class EdtSellerService
{
    /**
     * @param  array{code: string, name: string, phone?: string|null, warehouse_id: int|string, email: string, password: string, is_active?: bool}  $data
     */
    public function create(array $data): EdtSeller
    {
        return DB::transaction(function () use ($data): EdtSeller {
            $isActive = (bool) ($data['is_active'] ?? true);

            $user = User::query()->create([
                'name' => Uppercase::normalize($data['name']),
                'email' => self::normalizeEmail($data['email']),
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'is_active' => $isActive,
            ]);
            $user->warehouses()->sync([(int) $data['warehouse_id']]);
            $user->assignRole(EdtModule::SELLER_ROLE);

            return EdtSeller::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'warehouse_id' => (int) $data['warehouse_id'],
                'user_id' => $user->id,
                'is_active' => $isActive,
            ]);
        });
    }

    /**
     * La contraseña solo cambia si viene llena.
     *
     * @param  array{code?: string, name?: string, phone?: string|null, warehouse_id?: int|string, email?: string, password?: string|null, is_active?: bool}  $data
     *
     * @throws EdtRuleException si se intenta cambiar de bodega a un vendedor con rutas
     */
    public function update(EdtSeller $seller, array $data): EdtSeller
    {
        return DB::transaction(function () use ($seller, $data): EdtSeller {
            $warehouseChanged = array_key_exists('warehouse_id', $data)
                && (int) $data['warehouse_id'] !== (int) $seller->warehouse_id;

            if ($warehouseChanged && $seller->assignments()->exists()) {
                throw new EdtRuleException(
                    'Este vendedor ya tuvo rutas en su bodega y no se puede cambiar de bodega: crea un vendedor nuevo en la otra bodega.'
                );
            }

            $seller->update(collect($data)->only(['code', 'name', 'phone', 'warehouse_id', 'is_active'])->all());

            $user = $seller->user;
            $user->fill([
                'name' => $seller->name,
                'phone' => $seller->phone,
                'is_active' => $seller->is_active,
            ]);
            if (array_key_exists('email', $data)) {
                $user->email = self::normalizeEmail($data['email']);
            }
            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }
            $user->save();

            if ($warehouseChanged) {
                $user->warehouses()->sync([(int) $seller->warehouse_id]);
            }

            return $seller;
        });
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
