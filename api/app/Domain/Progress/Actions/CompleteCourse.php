<?php

declare(strict_types=1);

namespace App\Domain\Progress\Actions;

use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Models\CourseProgress;
use App\Support\Exceptions\DomainException;

/**
 * The learner-initiated completion — "I am done with this".
 *
 * Only flexible courses allow it early. A strict course refuses until every
 * completable item is finished, at which point RecalculateCourseProgress has
 * already completed it anyway, so this becomes a no-op that returns the
 * existing progress rather than an error.
 */
final class CompleteCourse
{
    public function handle(Enrollment $enrollment): CourseProgress
    {
        $enrollment->loadMissing(['course', 'progress']);

        if ($enrollment->course->completion_mode === CompletionMode::Strict) {
            $progress = $enrollment->progress;

            if ($progress === null || $progress->completed_items < $progress->total_items) {
                throw new class('Finish every lesson before marking this course complete.') extends DomainException
                {
                    public function errorCode(): string
                    {
                        return 'course_not_complete';
                    }

                    public function status(): int
                    {
                        return 422;
                    }
                };
            }
        }

        $progress = $enrollment->progress;

        if ($progress === null || $progress->completed_at !== null) {
            return $progress ?? app(RecalculateCourseProgress::class)->handle($enrollment);
        }

        $progress->forceFill(['completed_at' => now()])->save();

        $enrollment->forceFill([
            'status' => EnrollmentStatus::Completed,
            'completed_at' => now(),
        ])->save();

        CourseCompleted::dispatch($enrollment);

        return $progress->refresh();
    }
}
