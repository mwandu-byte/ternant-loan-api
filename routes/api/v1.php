<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
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

Route::middleware('auth:api')->prefix('customers')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    Route::post('/', [CustomerController::class, 'store'])->middleware('permission:customers.create');
    Route::get('{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    Route::put('{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
    Route::delete('{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');
});

// ---------------------------------------------------------------------------
// Future modules — not implemented yet. Each will be its own route group,
// behind `auth:api` plus the relevant `permission:*` middleware, once its
// controller exists. Left commented rather than wired up: referencing a
// controller class that doesn't exist yet would fatal-error route
// registration/caching.
// ---------------------------------------------------------------------------
// Route::middleware(['auth:api', 'permission:collateral.view'])->prefix('collaterals')->group(...);
// Route::middleware(['auth:api', 'permission:loans.view'])->prefix('loans')->group(...);
// Route::middleware(['auth:api', 'permission:repayments.view'])->prefix('repayments')->group(...);
// Route::middleware(['auth:api', 'permission:payments.view'])->prefix('payments')->group(...);
// Route::middleware(['auth:api', 'permission:penalties.view'])->prefix('penalties')->group(...);
// Route::middleware(['auth:api', 'permission:reports.view'])->prefix('reports')->group(...);
// Route::middleware(['auth:api', 'permission:dashboard.view'])->prefix('dashboard')->group(...);
// Route::middleware(['auth:api', 'permission:configuration.view'])->prefix('configuration')->group(...);
// Route::middleware(['auth:api', 'permission:users.view'])->prefix('users')->group(...);
// Route::middleware(['auth:api', 'permission:roles.view'])->prefix('roles')->group(...);
