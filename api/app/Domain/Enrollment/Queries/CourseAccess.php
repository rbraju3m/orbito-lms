<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
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
 * enrollment, and preview items. Phase 9 adds drip and prerequisites; Phase 10
 * purchase; Phase 16 subscription, bundle and membership. Nothing else needs to
 * change when they do.
 *
 * This is a DIFFERENT question from authorization. "May they perform this
 * operation?" is a Policy. See docs/ROLES_PERMISSIONS.md §6.
 */
final class CourseAccess
{
    /**
     * Per-request memo: the player asks this once per item.
     *
     * @var array<string, AccessDecision>
     */
    private array $memo = [];

    public function for(?User $user, Course $course): AccessDecision
    {
        $key = ($user->id ?? 0).':'.$course->id;

        return $this->memo[$key] ??= $this->resolve($user, $course);
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
            return $decision;
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
            return AccessDecision::deny('enrollment_expired');
        }

        return match (true) {
            $enrollment->status->grantsAccess() => AccessDecision::grant(
                'enrollment',
                $enrollment,
                $enrollment->expires_at,
            ),
            default => AccessDecision::deny('enrollment_'.$enrollment->status->value),
        };
    }
}
