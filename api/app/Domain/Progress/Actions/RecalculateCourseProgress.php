<?php

declare(strict_types=1);

namespace App\Domain\Progress\Actions;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Models\CourseProgress;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Facades\DB;

/**
 * Writes the stored aggregate (ADR-02).
 *
 * Two aggregate queries, not a walk over the curriculum. This is the whole
 * reason "My courses" is one indexed read instead of O(courses × items).
 */
final class RecalculateCourseProgress
{
    public function handle(Enrollment $enrollment): CourseProgress
    {
        $enrollment->loadMissing('course');

        // Only published, completable items count. A downloadable resource is
        // not something a learner finishes.
        $completableIds = CourseItem::query()
            ->where('course_id', $enrollment->course_id)
            ->published()
            ->whereNot('type', 'resource')
            ->pluck('id');

        $total = $completableIds->count();

        $completed = $total === 0 ? 0 : ItemProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', ItemProgressStatus::Completed)
            ->whereIn('course_item_id', $completableIds)
            ->count();

        $percent = $total === 0 ? 0.0 : round(($completed / $total) * 100, 2);

        $progress = DB::transaction(function () use ($enrollment, $total, $completed, $percent): CourseProgress {
            /** @var CourseProgress $progress */
            $progress = CourseProgress::firstOrNew(['enrollment_id' => $enrollment->id]);

            $progress->fill([
                'course_id' => $enrollment->course_id,
                'user_id' => $enrollment->user_id,
                'completed_items' => $completed,
                'total_items' => $total,
                'percent' => $percent,
            ]);

            $progress->started_at ??= $completed > 0 ? now() : null;
            $progress->save();

            return $progress;
        });

        $this->maybeCompleteCourse($enrollment, $progress);

        return $progress->refresh();
    }

    /**
     * A strict course completes itself when everything is done. A flexible one
     * waits for the learner to say so — that is what the mode means.
     */
    private function maybeCompleteCourse(Enrollment $enrollment, CourseProgress $progress): void
    {
        if ($progress->completed_at !== null || $progress->total_items === 0) {
            return;
        }

        if ($progress->completed_items < $progress->total_items) {
            return;
        }

        $progress->forceFill(['completed_at' => now()])->save();

        $enrollment->forceFill([
            'status' => EnrollmentStatus::Completed,
            'completed_at' => now(),
        ])->save();

        CourseCompleted::dispatch($enrollment);
    }
}
