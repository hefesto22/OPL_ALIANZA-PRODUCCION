<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use BezhanSalleh\FilamentShield\Support\Utils;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create Super Admin role
        $superAdmin = Role::firstOrCreate(
            ['name' => Utils::getSuperAdminName()],
            ['guard_name' => 'web']
        );

        // Create Panel User role
        $panelUser = Role::firstOrCreate(
            ['name' => Utils::getPanelUserRoleName()],
            ['guard_name' => 'web']
        );

        // Define base permissions for resources
        $resources = [
            'user' => ['view_any', 'view', 'create', 'update', 'delete', 'restore', 'force_delete'],
            'role' => ['view_any', 'view', 'create', 'update', 'delete'],
        ];

        $permissions = [];

        foreach ($resources as $resource => $actions) {
            foreach ($actions as $action) {
                $permissions[] = Permission::firstOrCreate(
                    ['name' => "{$action}_{$resource}"],
                    ['guard_name' => 'web']
                );
            }
        }

        // Page permissions
        $pagePermissions = [
            'page_MyProfilePage',
            'page_ActivityLogPage',
        ];

        foreach ($pagePermissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web']
            );
        }

        // Super Admin gets all permissions
        $superAdmin->syncPermissions(Permission::all());

        // Panel User gets basic view permissions
        $panelUser->syncPermissions([
            'page_MyProfilePage',
        ]);
    }
}