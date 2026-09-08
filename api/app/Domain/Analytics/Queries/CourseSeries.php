<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Queries;

use App\Domain\Analytics\Data\DateRange;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Catalog\Models\Course;
use Illuminate\Support\Collection;
use stdClass;

/**
 * One course over time, and the leaderboard of all of them.
 *
 * Rollups only (ADR-08), so the figure on a course page and the figure in the
 * academy total are the same number read from the same table.
 */
final class CourseSeries
{
    /**
     * @return array{totals: array<string, int>, series: list<array<string, mixed>>, currency: string}
     */
    public function forCourse(Course $course, DateRange $range): array
    {
        $rows = DailyCourseStat::query()
            ->where('course_id', $course->id)
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->orderBy('date')
            ->get();

        $byDate = $rows->keyBy(fn (DailyCourseStat $row): string => $row->date->toDateString());

        return [
            'totals' => [
                'views' => (int) $rows->sum('views'),
                'enrollments' => (int) $rows->sum('enrollments'),
                'completions' => (int) $rows->sum('completions'),
                'revenue_minor' => (int) $rows->sum('revenue_minor'),
                'peak_daily_active' => (int) $rows->max('active_learners'),
            ],
            'series' => array_map(fn (string $date): array => [
                'date' => $date,
                'views' => (int) ($byDate[$date]->views ?? 0),
                'enrollments' => (int) ($byDate[$date]->enrollments ?? 0),
                'completions' => (int) ($byDate[$date]->completions ?? 0),
                'revenue_minor' => (int) ($byDate[$date]->revenue_minor ?? 0),
                'active_learners' => (int) ($byDate[$date]->active_learners ?? 0),
            ], $range->dates()),
            'currency' => (string) ($rows->first()->currency ?? strtoupper((string) config('orbito.currency.base'))),
        ];
    }

    /**
     * The leaderboard.
     *
     * `$courseIds` narrows it to what the caller may see — an instructor gets
     * their own courses and nobody else's. Filtering by what the reader may
     * open, rather than by a query parameter, is the §14 rule: a row that 403s
     * when clicked is a bug, not a permission check.
     *
     * @param  list<int>|null  $courseIds  null means every course
     * @return Collection<int, stdClass>
     */
    public function leaderboard(DateRange $range, ?array $courseIds, int $limit = 20): Collection
    {
        return DailyCourseStat::query()
            ->join('courses', 'courses.id', '=', 'analytics_daily_course.course_id')
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($courseIds !== null, fn ($query) => $query->whereIn('course_id', $courseIds ?? []))
            ->groupBy('course_id', 'courses.uuid', 'courses.title', 'courses.slug')
            ->selectRaw('course_id, courses.uuid, courses.title, courses.slug')
            ->selectRaw('SUM(views) as views, SUM(enrollments) as enrollments')
            ->selectRaw('SUM(completions) as completions, SUM(revenue_minor) as revenue_minor')
            ->orderByDesc('enrollments')
            ->orderByDesc('course_id')
            ->limit($limit)
            // Aggregate rows, not DailyCourseStat models.
            ->toBase()
            ->get();
    }
}
