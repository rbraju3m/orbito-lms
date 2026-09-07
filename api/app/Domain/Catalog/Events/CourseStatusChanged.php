<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Models\Course;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One event for every lifecycle move. Listeners branch on `$from`/`$to` rather
 * than us shipping five near-identical events — and the usage counters need
 * both ends of the transition to stay correct.
 */
final class CourseStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Course $course,
        public readonly CourseStatus $from,
        public readonly CourseStatus $to,
        public readonly ?int $actorId = null,
    ) {}

    public function became(CourseStatus $status): bool
    {
        return $this->to === $status && $this->from !== $status;
    }

    public function left(CourseStatus $status): bool
    {
        return $this->from === $status && $this->to !== $status;
    }
}
