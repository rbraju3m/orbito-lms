<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Queries;

use App\Domain\Analytics\Data\DateRange;
use App\Domain\Analytics\Models\DailyInstructorStat;
use Illuminate\Support\Collection;
use stdClass;

/**
 * One instructor over time, and the ranking of all of them.
 *
 * `rating_avg` is a stored SNAPSHOT per day, never recomputed — a trend line
 * has to survive a course being deleted, and re-deriving would silently
 * rewrite history every time one was.
 */
final class InstructorSeries
{
    /**
     * @return array{totals: array<string, int|float>, series: list<array<string, mixed>>, currency: string}
     */
    public function forInstructor(int $instructorId, DateRange $range): array
    {
        $rows = DailyInstructorStat::query()
            ->where('instructor_id', $instructorId)
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->orderBy('date')
            ->get();

        $byDate = $rows->keyBy(fn (DailyInstructorStat $row): string => $row->date->toDateString());

        return [
            'totals' => [
                'enrollments' => (int) $rows->sum('enrollments'),
                'revenue_minor' => (int) $rows->sum('revenue_minor'),
                // The latest snapshot, not an average of averages — which
                // would be a number with no meaning at all.
                'rating_avg' => (float) ($rows->last()->rating_avg ?? 0),
            ],
            'series' => array_map(fn (string $date): array => [
                'date' => $date,
                'enrollments' => (int) ($byDate[$date]->enrollments ?? 0),
                'revenue_minor' => (int) ($byDate[$date]->revenue_minor ?? 0),
                'rating_avg' => (float) ($byDate[$date]->rating_avg ?? 0),
            ], $range->dates()),
            'currency' => (string) ($rows->first()->currency ?? strtoupper((string) config('orbito.currency.base'))),
        ];
    }

    /** @return Collection<int, stdClass> */
    public function leaderboard(DateRange $range, int $limit = 20): Collection
    {
        return DailyInstructorStat::query()
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->groupBy('instructor_id')
            ->selectRaw('instructor_id, SUM(enrollments) as enrollments, SUM(revenue_minor) as revenue_minor')
            ->selectRaw('MAX(rating_avg) as rating_avg')
            ->orderByDesc('revenue_minor')
            ->orderByDesc('instructor_id')
            ->limit($limit)
            ->toBase()
            ->get();
    }
}
