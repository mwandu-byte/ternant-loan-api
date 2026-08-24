<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollateralController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\GracePeriodController;
use App\Http\Controllers\Api\V1\InterestRuleController;
use App\Http\Controllers\Api\V1\LoanAmountConfigurationController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PenaltyRuleController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RepaymentController;
use App\Http\Controllers\Api\V1\RepaymentFrequencyController;
use App\Http\Controllers\Api\V1\RepaymentScheduleController;
use App\Http\Controllers\Api\V1\RepaymentTermController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword'])->middleware('throttle:change-password');
    });
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::post('/', [CustomerController::class, 'store'])->middleware('permission:customers.create');
        Route::get('{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
        Route::put('{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
        Route::delete('{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');

        Route::prefix('{customer}/collaterals')->group(function () {
            Route::get('/', [CollateralController::class, 'index'])->middleware('permission:collateral.view');
            Route::post('/', [CollateralController::class, 'store'])->middleware('permission:collateral.create');
            Route::get('{collateral}', [CollateralController::class, 'show'])->middleware('permission:collateral.view');
            Route::put('{collateral}', [CollateralController::class, 'update'])->middleware('permission:collateral.update');
            Route::delete('{collateral}', [CollateralController::class, 'destroy'])->middleware('permission:collateral.delete');
        });
    });

    Route::prefix('loans')->group(function () {
        Route::get('/', [LoanController::class, 'index'])->middleware('permission:loans.view');
        Route::post('/', [LoanController::class, 'store'])->middleware('permission:loans.create');
        Route::get('{loan}', [LoanController::class, 'show'])->middleware('permission:loans.view');
        Route::put('{loan}', [LoanController::class, 'update'])->middleware('permission:loans.update');
        Route::delete('{loan}', [LoanController::class, 'destroy'])->middleware('permission:loans.delete');

        Route::prefix('{loan}/repayment-schedules')->group(function () {
            Route::get('/', [RepaymentScheduleController::class, 'indexForLoan'])->middleware('permission:repayment-schedules.view');
            Route::post('generate', [RepaymentScheduleController::class, 'generate'])->middleware('permission:repayment-schedules.create');
        });

        Route::prefix('{loan}/payments')->group(function () {
            Route::post('/', [PaymentController::class, 'store'])->middleware('permission:payments.create');
        });
    });

    Route::prefix('repayment-schedules')->group(function () {
        Route::get('/', [RepaymentScheduleController::class, 'index'])->middleware('permission:repayment-schedules.view');
        Route::get('{repayment}', [RepaymentScheduleController::class, 'show'])->middleware('permission:repayment-schedules.view');
    });

    Route::prefix('repayments')->group(function () {
        Route::get('/', [RepaymentController::class, 'index'])->middleware('permission:repayments.view');
        Route::post('/', [RepaymentController::class, 'store'])->middleware('permission:repayments.create');
        Route::get('{repayment}', [RepaymentController::class, 'show'])->middleware('permission:repayments.view');
    });

    Route::prefix('payments')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::get('{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
    });

    Route::prefix('loan-configurations')->group(function () {
        Route::prefix('interest-rules')->group(function () {
            Route::get('/', [InterestRuleController::class, 'index'])->middleware('permission:loan-configurations.view');
            Route::post('/', [InterestRuleController::class, 'store'])->middleware('permission:loan-configurations.create');
            Route::get('{rule}', [InterestRuleController::class, 'show'])->middleware('permission:loan-configurations.view');
            Route::put('{rule}', [InterestRuleController::class, 'update'])->middleware('permission:loan-configurations.update');
            Route::delete('{rule}', [InterestRuleController::class, 'destroy'])->middleware('permission:loan-configurations.delete');
        });

        Route::prefix('repayment-frequencies')->group(function () {
            Route::get('/', [RepaymentFrequencyController::class, 'index'])->middleware('permission:loan-configurations.view');
            Route::post('/', [RepaymentFrequencyController::class, 'store'])->middleware('permission:loan-configurations.create');
            Route::get('{frequency}', [RepaymentFrequencyController::class, 'show'])->middleware('permission:loan-configurations.view');
            Route::put('{frequency}', [RepaymentFrequencyController::class, 'update'])->middleware('permission:loan-configurations.update');
            Route::delete('{frequency}', [RepaymentFrequencyController::class, 'destroy'])->middleware('permission:loan-configurations.delete');
        });

        Route::prefix('repayment-terms')->group(function () {
            Route::get('/', [RepaymentTermController::class, 'index'])->middleware('permission:loan-configurations.view');
            Route::post('/', [RepaymentTermController::class, 'store'])->middleware('permission:loan-configurations.create');
            Route::get('{term}', [RepaymentTermController::class, 'show'])->middleware('permission:loan-configurations.view');
            Route::put('{term}', [RepaymentTermController::class, 'update'])->middleware('permission:loan-configurations.update');
            Route::delete('{term}', [RepaymentTermController::class, 'destroy'])->middleware('permission:loan-configurations.delete');
        });

        Route::prefix('grace-period')->group(function () {
            Route::get('/', [GracePeriodController::class, 'show'])->middleware('permission:loan-configurations.view');
            Route::put('/', [GracePeriodController::class, 'update'])->middleware('permission:loan-configurations.update');
        });

        Route::prefix('penalty-rules')->group(function () {
            Route::get('/', [PenaltyRuleController::class, 'index'])->middleware('permission:loan-configurations.view');
            Route::post('/', [PenaltyRuleController::class, 'store'])->middleware('permission:loan-configurations.create');
            Route::get('{rule}', [PenaltyRuleController::class, 'show'])->middleware('permission:loan-configurations.view');
            Route::put('{rule}', [PenaltyRuleController::class, 'update'])->middleware('permission:loan-configurations.update');
            Route::delete('{rule}', [PenaltyRuleController::class, 'destroy'])->middleware('permission:loan-configurations.delete');
        });

        Route::prefix('loan-amount')->group(function () {
            Route::get('/', [LoanAmountConfigurationController::class, 'show'])->middleware('permission:loan-configurations.view');
            Route::put('/', [LoanAmountConfigurationController::class, 'update'])->middleware('permission:loan-configurations.update');
        });
    });

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::put('{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::delete('{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
        Route::get('{user}/permissions', [UserController::class, 'permissions'])->middleware('permission:users.view');

        Route::prefix('{user}/roles')->group(function () {
            Route::put('/', [UserController::class, 'syncRoles'])->middleware('permission:users.update');
            Route::post('/', [UserController::class, 'addRole'])->middleware('permission:users.update');
            Route::delete('{role}', [UserController::class, 'removeRole'])->middleware('permission:users.update');
        });
    });

    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('/', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::get('{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
        Route::put('{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
        Route::delete('{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

        Route::prefix('{role}/permissions')->group(function () {
            Route::put('/', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.update');
            Route::post('/', [RoleController::class, 'addPermission'])->middleware('permission:roles.update');
            Route::delete('{permission}', [RoleController::class, 'revokePermission'])->middleware('permission:roles.update');
        });
    });

    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
        Route::post('/', [PermissionController::class, 'store'])->middleware('permission:permissions.create');
        Route::get('{permission}', [PermissionController::class, 'show'])->middleware('permission:permissions.view');
        Route::put('{permission}', [PermissionController::class, 'update'])->middleware('permission:permissions.update');
        Route::delete('{permission}', [PermissionController::class, 'destroy'])->middleware('permission:permissions.delete');
    });

    // -----------------------------------------------------------------------
    // Future modules — not implemented yet. Each will be its own route
    // group, behind the relevant `permission:*` middleware, once its
    // controller exists. Left commented rather than wired up: referencing
    // a controller class that doesn't exist yet would fatal-error route
    // registration/caching.
    // -----------------------------------------------------------------------
    // Route::middleware('permission:penalties.view')->prefix('penalties')->group(...);
    // Route::middleware('permission:reports.view')->prefix('reports')->group(...);
    // Route::middleware('permission:dashboard.view')->prefix('dashboard')->group(...);
});
