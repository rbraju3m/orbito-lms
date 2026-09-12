<?php

declare(strict_types=1);

namespace App\Domain\Live\Events;

use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Models\Webinar;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A webinar was published, taken back to draft, or called off.
 *
 * Carries BOTH ends of the move, like `CourseStatusChanged`: a listener that
 * only knows the new state cannot tell a publish from a re-publish, and the
 * previous status is gone by the time a queued one runs.
 *
 * Nothing listens yet. The obvious listener — telling the registrants when an
 * event they hold a place at is called off — is its own slice, and it needs a
 * notification type rather than a hook bolted onto this.
 */
final class WebinarStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Webinar $webinar,
        public readonly WebinarStatus $from,
        public readonly WebinarStatus $to,
        public readonly int $actorId,
    ) {}

    /** Did it ENTER this state? A no-op call never reaches here. */
    public function became(WebinarStatus $status): bool
    {
        return $this->to === $status;
    }

    public function left(WebinarStatus $status): bool
    {
        return $this->from === $status;
    }
}
