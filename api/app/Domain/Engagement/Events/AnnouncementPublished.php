<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Events;

use App\Domain\Engagement\Models\Announcement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An announcement became visible to learners.
 *
 * Fired on the transition, not on every save: an instructor fixing a typo in
 * a published announcement must not notify everybody a second time.
 *
 * Nothing consumes this yet — the notifications slice will. It is dispatched
 * now rather than added later so the transition has one definition from the
 * start, and so the publish path does not have to change when notifications
 * arrive.
 */
final class AnnouncementPublished
{
    use Dispatchable;

    public function __construct(public readonly Announcement $announcement) {}
}
