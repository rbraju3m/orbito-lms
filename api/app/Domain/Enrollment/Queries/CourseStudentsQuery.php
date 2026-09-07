<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The instructor's student roster.
 *
 * Progress arrives as an eager-loaded relation, not a recomputed aggregate:
 * `course_progress` already holds the percentage (ADR-02), so a class of forty
 * costs three queries rather than forty.
 *
 * `enrollments` is in the academy's schema and `users` is central, so NOTHING
 * here may join the two. Eager-loading the learner is fine — that is a second
 * query on the central connection — but a `whereHas`, an `orderBy` subquery or
 * any other single statement spanning both raises "table users doesn't exist"
 * inside the tenant schema. Anything needing the central side resolves it
 * first, then filters by id.
 */
final class CourseStudentsQuery
{
    /**
     * @return LengthAwarePaginator<int, Enrollment>
     */
    public function forCourse(
        Course $course,
        ?string $status = null,
        ?string $search = null,
        string $sort = 'recent',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = Enrollment::query()
            ->where('course_id', $course->id)
            ->with(['user:id,uuid,name,email,tenant_id', 'progress'])
            ->when(
                $status !== null && $status !== 'all',
                fn (Builder $q) => $q->where('status', EnrollmentStatus::from((string) $status)),
            );

        if ($search !== null && $search !== '') {
            $query->whereIn('user_id', $this->matchingUserIds($search));
        }

        // `enrolled_at` has second precision, so a tie is entirely possible in
        // a bulk import — without the id tiebreak a row can land on two pages
        // or on none. Same lesson as the Phase 8 grading queue.
        /*
         * Sorting by learner NAME is deliberately not offered. The name is a
         * central column and the rows are tenant-side, so ordering by it means
         * either pulling every enrolled id into an IN list or paging in PHP —
         * both of which break down exactly where a roster gets big enough to
         * want sorting. The real fix is denormalising the learner's name onto
         * `enrollments` and maintaining it by event, the way every other
         * cross-boundary read in this codebase is handled.
         */
        match ($sort) {
            'oldest' => $query->orderBy('enrolled_at')->orderBy('id'),
            default => $query->orderByDesc('enrolled_at')->orderByDesc('id'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Candidate learners, resolved on the CENTRAL connection.
     *
     * Scoped to the current academy, which is not merely an optimisation: an
     * unscoped search would match accounts belonging to other tenants, and an
     * instructor typing a name would learn who exists on the rest of the
     * platform.
     *
     * @return list<int>
     */
    private function matchingUserIds(string $search): array
    {
        $tenantId = tenancy()->tenant?->getTenantKey();

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
