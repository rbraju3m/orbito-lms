<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseSetting;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Support\PrerequisiteCheck;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Actions\RecalculateCourseProgress;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single way an enrollment row comes into existence.
 *
 * Every gate this applies is a server-side fact — status, price, seats,
 * prerequisites. What a given path may skip is declared on the intent, not
 * decided here, so there is one readable list of who gets past what.
 */
final class EnrollInCourse
{
    public function __construct(private readonly PrerequisiteCheck $prerequisites) {}

    public function handle(User $user, Course $course, ?EnrollmentIntent $intent = null): Enrollment
    {
        $intent ??= EnrollmentIntent::free();
        $course->loadMissing('setting');

        if (! $course->status->isLive()) {
            throw EnrollmentRejected::notAvailable();
        }

        // A paid course can only be entered through a verified payment
        // (ADR-05). The free path is never that.
        if (! $intent->bypassPayment && $course->pricing_model !== PricingModel::Free) {
            throw EnrollmentRejected::requiresPayment();
        }

        if (Enrollment::where('course_id', $course->id)->where('user_id', $user->id)->exists()) {
            throw EnrollmentRejected::alreadyEnrolled();
        }

        if (! $intent->bypassPrerequisites) {
            $unmet = $this->prerequisites->unmetFor($user, $course);

            if ($unmet->isNotEmpty()) {
                throw EnrollmentRejected::prerequisitesUnmet($unmet);
            }
        }

        $expiresAt = $intent->expiresAt ?? $this->defaultExpiry($course, $intent);

        $enrollment = $this->create($user, $course, $intent, $expiresAt);

        // Creates the course_progress row with the correct total_items.
        app(RecalculateCourseProgress::class)->handle($enrollment);

        CourseEnrolled::dispatch($enrollment);

        return $enrollment->refresh();
    }

    /**
     * The seat check and the insert must be one atomic decision.
     *
     * Counting outside the transaction lets two simultaneous requests both see
     * the last seat and both take it. Locking the settings row first serialises
     * enrolment per course — the narrowest lock that makes the count true when
     * the insert lands.
     */
    private function create(
        User $user,
        Course $course,
        EnrollmentIntent $intent,
        ?CarbonInterface $expiresAt,
    ): Enrollment {
        try {
            return DB::transaction(function () use ($user, $course, $intent, $expiresAt): Enrollment {
                $setting = CourseSetting::query()
                    ->where('course_id', $course->id)
                    ->lockForUpdate()
                    ->first();

                $maxStudents = $setting?->max_students;

                if ($maxStudents !== null
                    && $course->enrollments()->active()->count() >= $maxStudents) {
                    throw EnrollmentRejected::courseFull();
                }

                $enrollment = Enrollment::create([
                    'course_id' => $course->id,
                    'user_id' => $user->id,
                    'status' => EnrollmentStatus::Active,
                    'source' => $intent->source,
                    'source_id' => $intent->sourceId,
                    'enrolled_at' => now(),
                    'starts_at' => $intent->startsAt,
                    'expires_at' => $expiresAt,
                ]);

                // Denormalised on the course so a card never counts rows.
                $course->increment('enrollment_count');

                return $enrollment;
            });
        } catch (QueryException $e) {
            // The unique (course_id, user_id) index is the real defence against
            // a double-submit; the check above only makes the common case a
            // clean 409 instead of a 500.
            if ($this->isDuplicate($e)) {
                throw EnrollmentRejected::alreadyEnrolled();
            }

            throw $e;
        }
    }

    private function defaultExpiry(Course $course, EnrollmentIntent $intent): ?CarbonInterface
    {
        $days = $course->setting?->enrollment_expires_days;

        if ($days === null) {
            return null;
        }

        // Dated from when access begins, not from the row's creation: a seat
        // granted a month early must not spend that month expiring.
        return ($intent->startsAt ?? now())->copy()->addDays($days);
    }

    private function isDuplicate(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
