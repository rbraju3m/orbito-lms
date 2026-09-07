<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configurePasswords();
        $this->configureRateLimiting();
    }

    /**
     * Fail loudly in development for the mistakes that are silent in production:
     * lazy loading (N+1), silently discarded attributes, and mass assignment gaps.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private function configurePasswords(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(8));
    }

    /**
     * Named limiters used by routes. Values live in config/orbito.php so they
     * are tunable per environment without touching code. See docs/API.md §5.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute((int) config('orbito.rate_limits.api'))->by('u:'.$request->user()->getAuthIdentifier())
            : Limit::perMinute((int) config('orbito.rate_limits.guest'))->by('ip:'.$request->ip()));

        // Auth endpoints are limited per IP *and* per identifier so neither a
        // single host nor a single account can be hammered.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute((int) config('orbito.rate_limits.auth'))->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('orbito.rate_limits.auth'))
                ->by('id:'.strtolower((string) $request->input('email', 'anonymous'))),
        ]);

        RateLimiter::for('analytics', fn (Request $request) => Limit::perMinute(
            (int) config('orbito.rate_limits.analytics')
        )->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(
            (int) config('orbito.rate_limits.webhook')
        )->by('ip:'.$request->ip()));
    }
}
