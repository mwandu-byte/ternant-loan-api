<?php

use App\Support\BusinessOwnerRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Deployments only run migrations, never seeders, so the owner role
     * that self-registration assigns must be introduced here. On a fresh,
     * unseeded database this is a no-op: PermissionSeeder/RoleSeeder
     * create everything, and the seeded set stays exactly what they define.
     */
    public function up(): void
    {
        if (! Permission::where('guard_name', 'api')->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (BusinessOwnerRole::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        Role::firstOrCreate(['name' => BusinessOwnerRole::NAME, 'guard_name' => 'api'])
            ->syncPermissions(BusinessOwnerRole::PERMISSIONS);

        Role::where('name', 'admin')->where('guard_name', 'api')->first()
            ?->givePermissionTo('business-settings.update');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', BusinessOwnerRole::NAME)->where('guard_name', 'api')->delete();
        Permission::where('name', 'business-settings.update')->where('guard_name', 'api')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
