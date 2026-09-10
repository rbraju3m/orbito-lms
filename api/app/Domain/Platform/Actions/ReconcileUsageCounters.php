<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Models\InstructorProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;

/**
 * Recomputes every usage counter from source data and reports the drift.
 *
 * Counters are maintained by queued listeners, so a dead job leaves them wrong.
 * Drift greater than zero is a bug worth chasing, not something to quietly
 * correct and forget — hence the report, not just the correction.
 */
final class ReconcileUsageCounters
{
    public function __construct(private readonly UsageCounters $counters) {}

    /**
     * @return list<array{metric: string, owner: string, stored: int, actual: int, drift: int}>
     */
    public function handle(bool $dryRun = false): array
    {
        $drift = [];

        foreach ($this->truth() as [$metric, $owner, $actual]) {
            $stored = $this->counters->get($metric, $owner);

            if ($stored === $actual) {
                continue;
            }

            $drift[] = [
                'metric' => $metric->value,
                'owner' => $owner === null ? 'platform' : $owner->getMorphClass().':'.$owner->getKey(),
                'stored' => $stored,
                'actual' => $actual,
                'drift' => $actual - $stored,
            ];

            if (! $dryRun) {
                $this->counters->set($metric, $owner, $actual);
            }
        }

        return $drift;
    }

    /** @return list<array{0: UsageMetric, 1: User|null, 2: int}> */
    private function truth(): array
    {
        $rows = [
            [UsageMetric::CoursesTotal, null, Course::count()],
            [UsageMetric::CoursesPublished, null, Course::where('status', CourseStatus::Published)->count()],
            [UsageMetric::MediaFiles, null, Media::count()],
            [UsageMetric::StorageBytes, null, (int) Media::sum('size_bytes')],
            [
                UsageMetric::Instructors,
                null,
                InstructorProfile::where('status', InstructorStatus::Approved)->count(),
            ],
            /*
             * DISTINCT people, matching TrackStudentUsage's definition: one
             * learner in four courses is one seat. `distinct()->count(...)`
             * compiles to COUNT(DISTINCT user_id) — counting rows here would
             * make the reconcile disagree with the listener every night and
             * report drift that is not there.
             */
            [
                UsageMetric::Students,
                null,
                Enrollment::query()
                    ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
                    ->distinct()
                    ->count('user_id'),
            ],
        ];

        $owners = User::query()->whereIn('id', $this->ownerIds())->get();

        foreach ($owners as $owner) {
            $rows[] = [UsageMetric::CoursesTotal, $owner, Course::where('owner_id', $owner->id)->count()];
            $rows[] = [
                UsageMetric::CoursesPublished,
                $owner,
                Course::where('owner_id', $owner->id)->where('status', CourseStatus::Published)->count(),
            ];
            $rows[] = [UsageMetric::MediaFiles, $owner, Media::where('owner_id', $owner->id)->count()];
            $rows[] = [
                UsageMetric::StorageBytes,
                $owner,
                (int) Media::where('owner_id', $owner->id)->sum('size_bytes'),
            ];
        }

        return $rows;
    }

    /**
     * Every account that owns something inside this academy.
     *
     * `users` is central and `courses`, `media` and `instructor_profiles` are
     * not, so this CANNOT be a `has()` / `whereHas()` — those compile to a
     * subquery against the tenant schema from a central model's connection,
     * which fails outright. The ids are collected tenant-side first and the
     * accounts fetched centrally afterwards: two queries, one boundary, no
     * join across it.
     *
     * @return list<int>
     */
    private function ownerIds(): array
    {
        $ids = [
            ...Course::query()->distinct()->pluck('owner_id')->all(),
            ...Media::query()->distinct()->pluck('owner_id')->all(),
            ...InstructorProfile::query()->distinct()->pluck('user_id')->all(),
        ];

        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));
    }
}
