<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->permissions() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'api',
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function permissions(): array
    {
        return [
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',

            'collateral.view',
            'collateral.create',
            'collateral.update',
            'collateral.delete',

            'loans.view',
            'loans.create',
            'loans.update',
            'loans.delete',

            'repayment-schedules.view',
            'repayment-schedules.create',
            'repayment-schedules.update',

            'repayments.view',
            'repayments.create',
            'repayments.update',

            'payments.view',
            'payments.create',
            'payments.update',

            'penalties.view',
            'penalties.create',
            'penalties.update',

            'reports.view',

            'dashboard.view',

            'configuration.view',
            'configuration.update',

            'loan-configurations.view',
            'loan-configurations.create',
            'loan-configurations.update',
            'loan-configurations.delete',

            'users.view',
            'users.create',
            'users.update',
            'users.delete',

            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',

            'permissions.view',
            'permissions.create',
            'permissions.update',
            'permissions.delete',
        ];
    }
}
