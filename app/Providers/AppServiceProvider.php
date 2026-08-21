<?php

namespace App\Providers;

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
    }
}
