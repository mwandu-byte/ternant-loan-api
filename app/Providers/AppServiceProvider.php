<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
