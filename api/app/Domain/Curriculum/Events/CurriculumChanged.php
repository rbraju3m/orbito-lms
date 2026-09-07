<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Events;

use App\Domain\Catalog\Models\Course;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever the shape of a course's curriculum changes.
 *
 * Catalog listens to refresh its denormalised counters; Progress will listen
 * from Phase 6 to recount every enrollment's total.
 */
final class CurriculumChanged
{
    use Dispatchable;

    public function __construct(public readonly Course $course) {}
}
