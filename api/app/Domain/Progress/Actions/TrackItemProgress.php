<?php

declare(strict_types=1);

namespace App\Domain\Progress\Actions;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Events\ItemCompleted;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Facades\DB;

final class TrackItemProgress
{
    public function __construct(private readonly RecalculateCourseProgress $recalculate) {}

    /**
     * Called when a learner opens an item. Creates the progress row lazily —
     * eager creation for 10k students × 100 items would mean a million rows
     * nobody has touched.
     */
    public function view(Enrollment $enrollment, CourseItem $item): ItemProgress
    {
        $progress = $this->rowFor($enrollment, $item);

        $progress->forceFill([
            'first_seen_at' => $progress->first_seen_at ?? now(),
            'view_count' => $progress->view_count + 1,
            'status' => $progress->status->isComplete()
                ? ItemProgressStatus::Completed
                : ItemProgressStatus::InProgress,
        ])->save();

        $this->touchActivity($enrollment, $item);

        return $progress;
    }

    public function complete(Enrollment $enrollment, CourseItem $item): ItemProgress
    {
        $progress = $this->rowFor($enrollment, $item);

        if ($progress->status->isComplete()) {
            return $progress;
        }

        $progress->forceFill([
            'status' => ItemProgressStatus::Completed,
            'completed_at' => now(),
            'first_seen_at' => $progress->first_seen_at ?? now(),
        ])->save();

        $this->touchActivity($enrollment, $item);
        $this->recalculate->handle($enrollment);

        // Gamification, analytics and notifications hang off this — Progress
        // itself knows about none of them.
        ItemCompleted::dispatch($enrollment, $item);

        return $progress;
    }

    public function uncomplete(Enrollment $enrollment, CourseItem $item): ItemProgress
    {
        $progress = $this->rowFor($enrollment, $item);

        $progress->forceFill([
            'status' => ItemProgressStatus::InProgress,
            'completed_at' => null,
        ])->save();

        $this->recalculate->handle($enrollment);

        return $progress;
    }

    /**
     * Video heartbeat. `watch_max_seconds` only ever grows: scrubbing back
     * must not un-earn progress the learner already made.
     */
    public function recordWatch(
        Enrollment $enrollment,
        CourseItem $item,
        int $positionSeconds,
        int $completionThresholdPercent,
    ): ItemProgress {
        $progress = $this->rowFor($enrollment, $item);
        $duration = $item->duration_seconds;

        $position = max(0, min($positionSeconds, $duration > 0 ? $duration : $positionSeconds));
        $max = max($progress->watch_max_seconds, $position);

        $progress->forceFill([
            'watch_position_seconds' => $position,
            'watch_max_seconds' => $max,
            'first_seen_at' => $progress->first_seen_at ?? now(),
            'status' => $progress->status->isComplete()
                ? ItemProgressStatus::Completed
                : ItemProgressStatus::InProgress,
        ])->save();

        $this->touchActivity($enrollment, $item);

        // Watching to the threshold completes the item without the learner
        // having to click anything.
        $reachedThreshold = $duration > 0
            && ($max / $duration) * 100 >= $completionThresholdPercent;

        if ($reachedThreshold && ! $progress->status->isComplete()) {
            return $this->complete($enrollment, $item);
        }

        return $progress;
    }

    private function rowFor(Enrollment $enrollment, CourseItem $item): ItemProgress
    {
        return ItemProgress::firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'course_item_id' => $item->id],
            [
                'course_id' => $enrollment->course_id,
                'user_id' => $enrollment->user_id,
                'status' => ItemProgressStatus::NotStarted,
            ],
        );
    }

    /** Keeps "continue learning" pointing at the right place. */
    private function touchActivity(Enrollment $enrollment, CourseItem $item): void
    {
        DB::table('course_progress')
            ->where('enrollment_id', $enrollment->id)
            ->update([
                'last_item_id' => $item->id,
                'last_activity_at' => now(),
                'started_at' => DB::raw('COALESCE(started_at, NOW())'),
                'updated_at' => now(),
            ]);
    }
}
