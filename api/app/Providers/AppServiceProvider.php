<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Domain\Webhook\Support\DnsHostResolver;
use App\Domain\Webhook\Support\HostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            MediaUrlGenerator::class,
            fn () => new MediaUrlGenerator((int) config('orbito.media.signed_url_ttl_minutes', 15)),
        );

        // Real DNS. Tests swap in a map, so they never depend on the network.
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        // Tokens are central; Sanctum's own model would follow the tenant
        // connection. See the model.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureFactories();
        $this->configureModels();
        $this->configurePasswords();
        $this->configureRateLimiting();
    }

    /**
     * Domain models live outside App\Models, so Laravel's default factory
     * convention does not find them. Map
     *   App\Domain\<Context>\Models\Foo  ->  Database\Factories\<Context>\FooFactory
     * once here rather than adding newFactory() to every model.
     */
    private function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing(function (string $model): string {
            if (preg_match('/^App\\\\Domain\\\\(?<context>[^\\\\]+)\\\\Models\\\\(?<model>.+)$/', $model, $m) === 1) {
                return "Database\\Factories\\{$m['context']}\\{$m['model']}Factory";
            }

            return 'Database\\Factories\\'.class_basename($model).'Factory';
        });
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

        // The player posts a watch position every 15 seconds per item, so a
        // well-behaved client needs ~4/min. The cap is generous but finite.
        RateLimiter::for('watch', fn (Request $request) => Limit::perMinute(
            (int) config('orbito.rate_limits.watch')
        )->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // On top of `api`, on the one endpoint that writes to disk. How MUCH a
        // person may hold is `UploadQuota`'s job; this stops a script.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(
            (int) config('orbito.rate_limits.uploads')
        )->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(
            (int) config('orbito.rate_limits.webhook')
        )->by('ip:'.$request->ip()));
    }
}
