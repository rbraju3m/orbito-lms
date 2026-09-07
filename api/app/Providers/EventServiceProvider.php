<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Events\UserLoggedIn;
use App\Domain\Identity\Events\UserRegistered;
use App\Domain\Identity\Listeners\SendEmailVerification;
use App\Domain\Identity\Listeners\TouchLastSeen;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The domain event catalogue. Cross-context reactions are wired here and
 * nowhere else, so the fan-out from any event is readable in one place.
 */
final class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    private array $listen = [
        UserRegistered::class => [
            SendEmailVerification::class,
        ],
        UserLoggedIn::class => [
            TouchLastSeen::class,
        ],
    ];

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
