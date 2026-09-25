<?php

namespace App\Support;

/**
 * The role given to whoever self-registers a business. It holds every
 * tenant-level permission, including user management, but none of the
 * platform-only ones (businesses.*, role/permission mutations), so an
 * owner manages their own business and can never reach another tenant.
 *
 * Shared by RoleSeeder (fresh installs) and the data migration that
 * introduces the role on existing installs, which only run migrations.
 */
final class BusinessOwnerRole
{
    public const NAME = 'owner';

    public const PERMISSIONS = [
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
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.view',
        'permissions.view',
        'business-settings.update',
    ];
}
