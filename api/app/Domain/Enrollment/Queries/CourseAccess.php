<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Support\DripSchedule;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;

/**
 * ADR-03: the ONE service that answers "may this user consume this content?".
 *
 * Every gate calls it — the player, media signing, downloads, and from Phase 7
 * the quiz-start endpoint. The audited reference product answers this question
 * in many places and they disagree; there is exactly one implementation here.
 *
 * Access sources grow by phase. Today: owner, course staff, platform staff,
 * enrollment, preview items and drip. Phase 10 adds purchase; Phase 16
 * subscription, bundle and membership. Nothing else needs to change when they do.
 *
 * Prerequisites are deliberately NOT here. They gate enrolment, not ongoing
 * access — adding a prerequisite to a live course must not lock out the people
 * already inside it. See EnrollInCourse.
 *
 * This is a DIFFERENT question from authorization. "May they perform this
 * operation?" is a Policy. See docs/ROLES_PERMISSIONS.md §6.
 */
final class CourseAccess
{
    public function __construct(private readonly DripSchedule $drip) {}

    /*
     * There is deliberately NO memo here.
     *
     * This class used to cache its answer per (user, course). Laravel
     * memoises the controller instance on the Route object, and Route objects
     * outlive a request — so the cache did too, and a second request could be
     * served a `granted` decision made before the enrollment was suspended or
     * expired. Under php-fpm each request boots a fresh app and the leak is
     * invisible; under Octane, and in any test that changes enrollment state
     * between two calls, it is not.
     *
     * The saving was never large: the callers ask once or twice per request,
     * and `enrollmentFor` is a single lookup on a unique index. An
     * authorization decision is not worth caching for that.
     */
    public function for(?User $user, Course $course): AccessDecision
    {
        return $this->resolve($user, $course);
    }

    /**
     * Access to one item. Preview items are open to everyone, which is what
     * makes "try before you buy" work without a second code path.
     */
    public function forItem(?User $user, CourseItem $item): AccessDecision
    {
        $item->loadMissing('course');
        $decision = $this->for($user, $item->course);

        if ($decision->granted) {
            // Staff read the course to author it; drip is a learner schedule,
            // and an instructor who cannot open week 3 cannot edit week 3.
            if ($decision->isStaff()) {
                return $decision;
            }

            $state = $this->drip->forItem($item, $decision->enrollment);

            return $state->locked
                ? AccessDecision::deny('drip_locked', $state->unlocksAt, $state->meta(), $decision->enrollment)
                : $decision;
        }

        if ($item->is_preview && $item->is_published && $item->course->status->isLive()) {
            return AccessDecision::grant('preview');
        }

        return $decision;
    }

    public function enrollmentFor(User $user, Course $course): ?Enrollment
    {
        return Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function resolve(?User $user, Course $course): AccessDecision
    {
        if ($user === null) {
            return AccessDecision::deny('unauthenticated');
        }

        if ($course->owner_id === $user->id) {
            return AccessDecision::grant('owner');
        }

        // Anyone who can author the course can obviously read it.
        if ($user->can('update', $course)) {
            return AccessDecision::grant('course_staff');
        }

        if ($user->hasPermission('enrollment.view.any')) {
            return AccessDecision::grant('platform_staff');
        }

        $enrollment = $this->enrollmentFor($user, $course);

        if ($enrollment === null) {
            return AccessDecision::deny('not_enrolled');
        }

        if ($enrollment->hasExpired()) {
            return AccessDecision::deny('enrollment_expired', enrollment: $enrollment);
        }

        if (! $enrollment->hasStarted()) {
            return AccessDecision::deny(
                'enrollment_not_started',
                $enrollment->starts_at,
                enrollment: $enrollment,
            );
        }

        return match (true) {
            $enrollment->status->grantsAccess() => AccessDecision::grant(
                'enrollment',
                $enrollment,
                $enrollment->expires_at,
            ),
            default => AccessDecision::deny(
                'enrollment_'.$enrollment->status->value,
                enrollment: $enrollment,
            ),
        };
    }
}
