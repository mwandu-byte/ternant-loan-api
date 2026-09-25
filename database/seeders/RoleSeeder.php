<?php

namespace Database\Seeders;

use App\Support\BusinessOwnerRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $admin->syncPermissions(Permission::where('guard_name', 'api')->get());

        $owner = Role::firstOrCreate(['name' => BusinessOwnerRole::NAME, 'guard_name' => 'api']);
        $owner->syncPermissions(BusinessOwnerRole::PERMISSIONS);

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
            'repayment-schedules.view', 'repayment-schedules.create', 'repayment-schedules.update',
            'repayments.view', 'repayments.create', 'repayments.update',
            'payments.view', 'payments.create', 'payments.update',
            'penalties.view', 'penalties.create', 'penalties.update',
            'application-fees.view', 'application-fees.create',
            'guarantors.view', 'guarantors.create', 'guarantors.update', 'guarantors.delete',
            'dashboard.view',
            'reports.view',
            'data.view-all',
            'configuration.view', 'configuration.update',
            'loan-configurations.view', 'loan-configurations.create', 'loan-configurations.update', 'loan-configurations.delete',
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
            'repayment-schedules.view', 'repayment-schedules.create',
            'repayments.view', 'repayments.create',
            'payments.view', 'payments.create',
            'application-fees.view', 'application-fees.create',
            'guarantors.view', 'guarantors.create',
            'dashboard.view',
        ];
    }
}
