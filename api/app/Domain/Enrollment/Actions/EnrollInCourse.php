<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Actions\RecalculateCourseProgress;
use Illuminate\Support\Facades\DB;

final class EnrollInCourse
{
    public function handle(
        User $user,
        Course $course,
        EnrollmentSource $source = EnrollmentSource::Free,
        ?int $sourceId = null,
    ): Enrollment {
        $course->loadMissing('setting');

        if (! $course->status->isLive()) {
            throw EnrollmentRejected::notAvailable();
        }

        // A paid course can only be entered through a verified payment
        // (ADR-05). This action is never the path for that.
        if ($source === EnrollmentSource::Free && $course->pricing_model !== PricingModel::Free) {
            throw EnrollmentRejected::requiresPayment();
        }

        if (Enrollment::where('course_id', $course->id)->where('user_id', $user->id)->exists()) {
            throw EnrollmentRejected::alreadyEnrolled();
        }

        $maxStudents = $course->setting?->max_students;

        if ($maxStudents !== null
            && Enrollment::where('course_id', $course->id)->active()->count() >= $maxStudents) {
            throw EnrollmentRejected::courseFull();
        }

        $expiresDays = $course->setting?->enrollment_expires_days;

        $enrollment = DB::transaction(function () use ($user, $course, $source, $sourceId, $expiresDays): Enrollment {
            $enrollment = Enrollment::create([
                'course_id' => $course->id,
                'user_id' => $user->id,
                'status' => EnrollmentStatus::Active,
                'source' => $source,
                'source_id' => $sourceId,
                'enrolled_at' => now(),
                'expires_at' => $expiresDays !== null ? now()->addDays($expiresDays) : null,
            ]);

            // Denormalised on the course so a card never counts rows.
            $course->increment('enrollment_count');

            return $enrollment;
        });

        // Creates the course_progress row with the correct total_items.
        app(RecalculateCourseProgress::class)->handle($enrollment);

        CourseEnrolled::dispatch($enrollment);

        return $enrollment->refresh();
    }
}
