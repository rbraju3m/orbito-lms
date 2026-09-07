<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

/**
 * Wires the tenancy lifecycle: what happens when an academy is created,
 * deleted, or made the current tenant.
 *
 * Note what is NOT here: `makeTenancyMiddlewareHighestPriority()`. Tenancy is
 * resolved from the authenticated user, so the tenant middleware MUST run
 * AFTER `auth:sanctum`. Raising its priority would put it first and it would
 * find no user.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    /**
     * @return array<class-string, list<class-string|callable>>
     */
    public function events(): array
    {
        return [
            // Creating an academy provisions its schema, migrates it and
            // seeds it — in that order, each queued behind the last.
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    Jobs\SeedDatabase::class,
                ])->send(fn (Events\TenantCreated $event) => $event->tenant)
                    // Synchronous: a tenant whose schema appears some seconds
                    // later is a tenant whose owner can sign in and get a
                    // "table not found" 500.
                    ->shouldBeQueued(false),
            ],

            Events\TenantDeleted::class => [
                JobPipeline::make([Jobs\DeleteDatabase::class])
                    ->send(fn (Events\TenantDeleted $event) => $event->tenant)
                    ->shouldBeQueued(false),
            ],

            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],
        ];
    }

    public function register(): void {}

    public function boot(): void
    {
        $this->bootEvents();
    }

    private function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                $this->app['events']->listen($event, $listener);
            }
        }
    }
}
