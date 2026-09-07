<?php

declare(strict_types=1);

namespace App\Domain\Progress\Listeners;

use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Actions\RecalculateCourseProgress;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Adding or removing a lesson changes every enrolled learner's denominator.
 *
 * Queued: a course with 10,000 students must not make the instructor's "add
 * lesson" request wait on 10,000 recalculations.
 */
final class RecountEnrollmentTotals implements ShouldQueue
{
    public function __construct(private readonly RecalculateCourseProgress $recalculate) {}

    public function handle(CurriculumChanged $event): void
    {
        Enrollment::query()
            ->where('course_id', $event->course->id)
            ->active()
            ->cursor()
            ->each(fn (Enrollment $enrollment) => $this->recalculate->handle($enrollment));
    }
}
