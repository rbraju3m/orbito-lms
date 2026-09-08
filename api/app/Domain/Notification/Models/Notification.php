<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Database\Factories\Notification\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One row in somebody's inbox.
 *
 * Extends Laravel's DatabaseNotification for the read/unread behaviour, and
 * adds the one thing that matters here: this table lives in the ACADEMY's
 * schema while `User` is pinned central. Without LivesInTenantSchema,
 * `$user->notifications()` inherits the parent's pin and goes looking for a
 * `notifications` table in the central database — the multi-tenancy trap, reported as a
 * missing table rather than as a crossed boundary.
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property array<string, mixed> $data
 * @property CarbonInterface|null $read_at
 */
final class Notification extends DatabaseNotification
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, LivesInTenantSchema;

    /**
     * The stored `type` is a NotificationType value, not a class name, so it
     * reads as one.
     *
     * @param  Builder<Notification>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }
}
