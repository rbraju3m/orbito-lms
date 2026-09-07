<?php

declare(strict_types=1);

namespace App\Domain\Progress\Actions;

use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Exceptions\ProgressRejected;
use App\Domain\Progress\Models\CourseProgress;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Facades\DB;

/**
 * Start the course again.
 *
 * Two settings gate this, because starting over means different things
 * depending on where the learner is: a COMPLETED course is a retake
 * (`retake_allowed`), an unfinished one is a reset (`reset_progress_allowed`).
 * One action, two gates — the caller does not have to know which it is.
 */
final class ResetCourseProgress
{
    public function __construct(private readonly RecalculateCourseProgress $recalculate) {}

    public function handle(Enrollment $enrollment): CourseProgress
    {
        $enrollment->loadMissing('course.setting');
        $setting = $enrollment->course->setting;

        $isRetake = $enrollment->status === EnrollmentStatus::Completed
            || $enrollment->completed_at !== null;

        if ($isRetake && $setting?->retake_allowed === false) {
            throw ProgressRejected::retakeNotAllowed();
        }

        if (! $isRetake && $setting?->reset_progress_allowed === false) {
            throw ProgressRejected::resetNotAllowed();
        }

        DB::transaction(function () use ($enrollment): void {
            ItemProgress::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereIn('course_item_id', $this->selfMarkableItemIds($enrollment))
                ->delete();

            CourseProgress::where('enrollment_id', $enrollment->id)->update([
                'last_item_id' => null,
                'last_activity_at' => null,
                'started_at' => null,
                'completed_at' => null,
                'total_watch_seconds' => 0,
            ]);

            $enrollment->forceFill([
                'status' => EnrollmentStatus::Active,
                'completed_at' => null,
            ])->save();
        });

        // Recounts from what actually survived, so the percentage reflects the
        // earned completions still standing rather than assuming zero.
        return $this->recalculate->handle($enrollment);
    }

    /**
     * Only what the learner DECLARED is cleared.
     *
     * A passed quiz and a graded assignment are earned facts, not claims —
     * wiping them would show the item incomplete while a passing attempt sits
     * in the database, and if the quiz's attempts are exhausted the learner
     * could never complete the course again. `isSelfMarkable()` is exactly the
     * line between the two.
     *
     * @return list<int>
     */
    private function selfMarkableItemIds(Enrollment $enrollment): array
    {
        $earned = array_values(array_filter(
            ItemType::cases(),
            fn (ItemType $type): bool => ! $type->isSelfMarkable(),
        ));

        return CourseItem::query()
            ->where('course_id', $enrollment->course_id)
            ->whereNotIn('type', array_map(fn (ItemType $type): string => $type->value, $earned))
            ->pluck('id')
            ->all();
    }
}
