<?php

declare(strict_types=1);

namespace App\Domain\Progress\Events;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The fan-out point named in ARCHITECTURE §2. Progress knows nothing about
 * gamification, analytics or certificates — they listen to this.
 */
final class ItemCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Enrollment $enrollment,
        public readonly CourseItem $item,
    ) {}
}
