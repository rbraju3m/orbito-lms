<?php

declare(strict_types=1);

namespace App\Domain\Live\Enums;

/**
 * Where a run of a course is.
 *
 * `Open` is the only one that admits people, and it is set by hand rather
 * than derived: an academy may want a cohort visible and closed while it
 * decides, and "the deadline has passed" is a separate question the
 * enrolment check asks on top.
 */
enum CohortStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open for enrolment',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether a learner may join THIS run. */
    public function isEnrollable(): bool
    {
        return $this === self::Open;
    }

    /** Whether learners already in it still have access. */
    public function isVisible(): bool
    {
        return $this !== self::Draft;
    }
}
