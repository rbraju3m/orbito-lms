<?php

declare(strict_types=1);

namespace App\Domain\Progress\Events;

use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Certification listens to this from Phase 11; Gamification from Phase 14.
 */
final class CourseCompleted
{
    use Dispatchable;

    public function __construct(public readonly Enrollment $enrollment) {}
}
