<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;

/**
 * One switch somebody has actually moved.
 *
 * There is no row for a setting left alone (see the migration): the absence
 * of a row is the default, which is what lets a new type ship without a
 * backfill across every academy.
 *
 * `event_key` is NOT cast to NotificationType on purpose — a retired type
 * leaves rows behind, and reading a preference list must not become fatal
 * because one row names a case that no longer exists. Callers resolve it with
 * `NotificationType::tryFrom()` and skip what they do not recognise.
 *
 * @property int $id
 * @property int $user_id
 * @property string $event_key
 * @property NotificationChannel $channel
 * @property bool $enabled
 */
final class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'event_key', 'channel', 'enabled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ];
    }
}
