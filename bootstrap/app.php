<?php

use App\Exceptions\Auth\IncorrectCurrentPasswordException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Exceptions\Auth\InvalidResetTokenException;
use App\Exceptions\Authorization\InsufficientAdministrativeCoverageException;
use App\Exceptions\Collateral\CollateralNotFoundException;
use App\Exceptions\Customer\CustomerHasRelatedRecordsException;
use App\Exceptions\Loan\LoanAmountOutOfRangeException;
use App\Exceptions\Loan\LoanNotEditableException;
use App\Exceptions\Loan\LoanNotFoundException;
use App\Exceptions\Payment\InvalidDisbursementAmountException;
use App\Exceptions\Payment\LoanAlreadyDisbursedException;
use App\Exceptions\Payment\LoanNotEligibleForDisbursementException;
use App\Exceptions\Payment\PaymentNotFoundException;
use App\Exceptions\Penalty\PenaltyNotFoundException;
use App\Exceptions\Permission\PermissionInUseException;
use App\Exceptions\Receipt\DuplicateReceiptReferenceException;
use App\Exceptions\Repayment\LoanNotEligibleForRepaymentScheduleException;
use App\Exceptions\Repayment\RepaymentExceedsOutstandingAmountException;
use App\Exceptions\Repayment\RepaymentNotFoundException;
use App\Exceptions\Repayment\RepaymentScheduleAlreadyExistsException;
use App\Exceptions\Repayment\RepaymentScheduleDoesNotBelongToLoanException;
use App\Exceptions\Repayment\RepaymentScheduleNotFoundException;
use App\Exceptions\Role\RoleAssignedToUsersException;
use App\Exceptions\User\SelfDeletionNotAllowedException;
use App\Exceptions\User\SelfRoleModificationException;
use App\Exceptions\User\UserHasRelatedRecordsException;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // This is a JSON-only API — there is no "login" web route to redirect
        // guests to, so unauthenticated requests must never attempt one.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('penalties:accrue')->daily()->timezone(config('app.timezone'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::unauthorized();
            }
        });

        $exceptions->render(function (JWTException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::unauthorized();
            }
        });

        $exceptions->render(function (InvalidCredentialsException|InvalidRefreshTokenException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::unauthorized($e->getMessage());
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::forbidden();
            }
        });

        $exceptions->render(function (PermissionUnauthorizedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::forbidden();
            }
        });

        $exceptions->render(function (InvalidResetTokenException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422, (object) []);
            }
        });

        $exceptions->render(function (IncorrectCurrentPasswordException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422, ['current_password' => [$e->getMessage()]]);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::validationError($e->errors());
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('The requested resource was not found.', 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('This HTTP method is not supported for this route.', 405);
            }
        });

        $exceptions->render(function (CustomerHasRelatedRecordsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (CollateralNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 404);
            }
        });

        $exceptions->render(function (LoanNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 404);
            }
        });

        $exceptions->render(function (LoanAmountOutOfRangeException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (LoanNotEditableException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (RepaymentScheduleNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 404);
            }
        });

        $exceptions->render(function (RepaymentScheduleAlreadyExistsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (LoanNotEligibleForRepaymentScheduleException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (RepaymentNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 404);
            }
        });

        $exceptions->render(function (RepaymentScheduleDoesNotBelongToLoanException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (RepaymentExceedsOutstandingAmountException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (DuplicateReceiptReferenceException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (LoanNotEligibleForDisbursementException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (LoanAlreadyDisbursedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (InvalidDisbursementAmountException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (PaymentNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 404);
            }
        });

        $exceptions->render(function (PenaltyNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 404);
            }
        });

        $exceptions->render(function (SelfDeletionNotAllowedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (UserHasRelatedRecordsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (SelfRoleModificationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 403);
            }
        });

        $exceptions->render(function (RoleAssignedToUsersException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (PermissionInUseException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (InsufficientAdministrativeCoverageException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Too many requests. Please try again later.', 429);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! config('app.debug')) {
                return ApiResponse::error('Something went wrong. Please try again later.', 500);
            }
        });
    })->create();
