<?php

namespace App\Providers;

use App\Models\ApplicationFee;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use App\Policies\ApplicationFeePolicy;
use App\Policies\BusinessPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\GuarantorPolicy;
use App\Policies\LoanPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PenaltyPolicy;
use App\Policies\RepaymentPolicy;
use App\Policies\RepaymentSchedulePolicy;
use App\Policies\UserPolicy;
use App\Services\Repayment\OverdueService;
use App\Support\AccessScope;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound as a singleton (rather than left as the framework's default
        // per-resolution instance) because RepaymentScheduleResource — a
        // per-row JsonResource, never constructor-injected — resolves this
        // via app(OverdueService::class) once per row when rendering a
        // paginated list. A singleton means the single-row GracePeriod
        // config is fetched at most once per request instead of once per
        // row.
        $this->app->singleton(OverdueService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(Repayment::class, RepaymentPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(RepaymentSchedule::class, RepaymentSchedulePolicy::class);
        Gate::policy(Penalty::class, PenaltyPolicy::class);
        Gate::policy(Guarantor::class, GuarantorPolicy::class);
        Gate::policy(ApplicationFee::class, ApplicationFeePolicy::class);
        Gate::policy(Business::class, BusinessPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Tenant isolation, enforced centrally ahead of every policy: a
        // non-platform user is denied any ability on a tenant-owned model
        // that belongs to another business (or to no business), whatever
        // the individual policy would have said. Returning null defers to
        // the policy for everything else.
        Gate::before(function ($user, string $ability, array $arguments) {
            $model = $arguments[0] ?? null;

            if (! $user instanceof User || ! $model instanceof Model) {
                return null;
            }

            $businessId = AccessScope::businessIdOf($model);

            if ($businessId === false) {
                return null;
            }

            return AccessScope::canAccessBusiness($user, $businessId) ? null : false;
        });

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.forgot_password'))->by($request->ip());
        });

        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.reset_password'))->by($request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.register'))->by($request->ip());
        });

        RateLimiter::for('change-password', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.change_password'))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Scramble's docs routes (/docs/api, /docs/api.json) are always open in the
        // local environment; everywhere else they're blocked unless this gate allows
        // it, toggled by a single env var rather than being open by accident.
        Gate::define('viewApiDocs', function ($user = null) {
            return (bool) env('API_DOCS_ENABLED', false);
        });

        // config/scramble.php keeps 'security_strategy' as null on purpose: it needs
        // a live SecurityScheme object, and `php artisan config:cache` serializes the
        // *entire* Laravel config repository with var_export(), which SecurityScheme
        // doesn't support — so the object can never live in config() at all, cached
        // or not. Scramble itself sidesteps this: at boot it copies config('scramble')
        // into a singleton GeneratorConfig object it reads from afterward, not
        // Laravel's config repository. Setting the real value directly on that
        // singleton (after Scramble's own boot has already populated the rest of the
        // config) keeps the object completely invisible to config:cache, since
        // config:cache only ever inspects the Laravel config repository.
        Scramble::configure()->config(array_merge(config('scramble'), [
            'security_strategy' => [
                MiddlewareAuthSecurityStrategy::class,
                [
                    'middleware' => ['auth', 'auth:*'],
                    'scheme' => SecurityScheme::http('bearer', 'JWT')->as('bearerAuth'),
                ],
            ],
        ]));
    }
}
