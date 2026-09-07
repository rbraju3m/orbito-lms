<?php

declare(strict_types=1);

namespace App\Domain\Progress\Actions;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Models\CourseProgress;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Support\Facades\DB;

final class ResetCourseProgress
{
    public function __construct(private readonly RecalculateCourseProgress $recalculate) {}

    public function handle(Enrollment $enrollment): CourseProgress
    {
        DB::transaction(function () use ($enrollment): void {
            ItemProgress::where('enrollment_id', $enrollment->id)->delete();

            CourseProgress::where('enrollment_id', $enrollment->id)->update([
                'completed_items' => 0,
                'percent' => 0,
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

        return $this->recalculate->handle($enrollment);
    }
}
