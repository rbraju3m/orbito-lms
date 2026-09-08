<?php

declare(strict_types=1);

namespace App\Domain\Live\Events;

use App\Domain\Live\Models\LiveSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session now exists at a time.
 *
 * Named in EVENTS.md §4 as the Phase 15 event. Fires on CREATION and on a
 * reschedule, because both are "there is a thing in your calendar at this
 * moment" and a listener that cared about only one would have to be told
 * which — `$isNew` says so rather than making it two events that must never
 * disagree.
 */
final class SessionScheduled
{
    use Dispatchable;

    public function __construct(
        public readonly LiveSession $session,
        public readonly bool $isNew = true,
    ) {}
}
