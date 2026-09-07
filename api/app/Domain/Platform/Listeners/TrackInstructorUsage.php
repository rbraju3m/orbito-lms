<?php

declare(strict_types=1);

namespace App\Domain\Platform\Listeners;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Events\InstructorReviewed;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;

final class TrackInstructorUsage
{
    public function __construct(private readonly UsageCounters $counters) {}

    public function handle(InstructorReviewed $event): void
    {
        // Only approved instructors occupy a seat.
        if ($event->status === InstructorStatus::Approved) {
            $this->counters->increment(UsageMetric::Instructors);

            return;
        }

        $this->counters->decrement(UsageMetric::Instructors);
    }
}
