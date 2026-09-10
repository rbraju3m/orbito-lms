<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Events;

use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An enrolment started, or stopped, granting access to its course.
 *
 * The other enrolment events announce an OPERATION — somebody pressed
 * suspend, somebody pressed extend — and an operation is not a transition:
 * suspending an already-suspended row, or extending a live one, changes
 * nothing. Anything keeping a tally has to know which happened, and inferring
 * it after the fact is impossible because the previous status is gone by the
 * time a listener runs.
 *
 * So this fires ONLY on a real flip of `EnrollmentStatus::grantsAccess()`,
 * carrying the new answer. `CourseStatusChanged` solved the same problem for
 * Catalog with `became()` / `left()`; this is that idea for a boolean.
 */
final class EnrollmentAccessChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Enrollment $enrollment,
        /** The NEW answer: true if it grants access now, false if it stopped. */
        public readonly bool $grantsAccess,
    ) {}
}
