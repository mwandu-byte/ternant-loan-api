<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $admin->syncPermissions(Permission::where('guard_name', 'api')->get());

        $lender = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'api']);
        $lender->syncPermissions($this->lenderPermissions());

        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'api']);
        $staff->syncPermissions($this->staffPermissions());
    }

    /**
     * @return array<int, string>
     */
    private function lenderPermissions(): array
    {
        return [
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'collateral.view', 'collateral.create', 'collateral.update', 'collateral.delete',
            'loans.view', 'loans.create', 'loans.update', 'loans.delete',
            'repayments.view', 'repayments.create', 'repayments.update',
            'payments.view', 'payments.create', 'payments.update',
            'penalties.view', 'penalties.create', 'penalties.update',
            'dashboard.view',
            'reports.view',
            'configuration.view', 'configuration.update',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function staffPermissions(): array
    {
        return [
            'customers.view', 'customers.create', 'customers.update',
            'collateral.view', 'collateral.create', 'collateral.update',
            'loans.view',
            'repayments.view', 'repayments.create',
            'payments.view', 'payments.create',
            'dashboard.view',
        ];
    }
}
