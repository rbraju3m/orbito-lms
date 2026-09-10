<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Queries;

use App\Domain\Analytics\Data\DateRange;
use App\Domain\Analytics\Models\DailyPlatformStat;
use Illuminate\Support\Collection;

/**
 * The academy dashboard.
 *
 * Reads ROLLUPS ONLY (ADR-08). Nothing here touches the event log, the
 * enrolments table or the orders ledger — which is what makes the cost of this
 * page proportional to the range asked for rather than to the history behind
 * it, and what makes it the same number the course pages show.
 */
final class PlatformOverview
{
    /**
     * @return array{
     *     totals: array<string, int>,
     *     previous: array<string, int>,
     *     series: list<array<string, mixed>>,
     *     currency: string
     * }
     */
    public function handle(DateRange $range): array
    {
        $rows = $this->rows($range);
        $currency = $rows->first()->currency ?? strtoupper((string) config('orbito.currency.base'));

        return [
            'totals' => $this->totals($rows),
            // The same window immediately before, so the UI can say "vs. the
            // previous 30 days" without a second request or its own date maths.
            'previous' => $this->totals($this->rows($range->previous())),
            'series' => $this->series($rows, $range),
            'currency' => (string) $currency,
        ];
    }

    /** @return Collection<int, DailyPlatformStat> */
    private function rows(DateRange $range): Collection
    {
        return DailyPlatformStat::query()
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->orderBy('date')
            ->get();
    }

    /**
     * @param  Collection<int, DailyPlatformStat>  $rows
     * @return array<string, int>
     */
    private function totals(Collection $rows): array
    {
        return [
            'new_users' => (int) $rows->sum('new_users'),
            'new_enrollments' => (int) $rows->sum('new_enrollments'),
            'completions' => (int) $rows->sum('completions'),
            'revenue_minor' => (int) $rows->sum('revenue_minor'),
            // Already INSIDE revenue_minor; reported beside it so a reader can
            // see how much of the total came from downloads.
            'download_revenue_minor' => (int) $rows->sum('download_revenue_minor'),
            /*
             * NOT summed. Active learners are distinct PEOPLE, and adding up
             * thirty daily counts counts a regular five times over. The
             * honest answer over a range needs the log, which dashboards do
             * not read — so this reports the busiest single day and the API
             * names the field for what it is.
             */
            'peak_daily_active' => (int) $rows->max('active_learners'),
        ];
    }

    /**
     * @param  Collection<int, DailyPlatformStat>  $rows
     * @return list<array<string, mixed>>
     */
    private function series(Collection $rows, DateRange $range): array
    {
        $byDate = $rows->keyBy(fn (DailyPlatformStat $row): string => $row->date->toDateString());

        // Densified: a day with no rollup row comes back as zeros rather than
        // missing, or a chart draws a straight line across the gap and
        // reports activity that never happened.
        return array_map(fn (string $date): array => [
            'date' => $date,
            'new_users' => (int) ($byDate[$date]->new_users ?? 0),
            'new_enrollments' => (int) ($byDate[$date]->new_enrollments ?? 0),
            'completions' => (int) ($byDate[$date]->completions ?? 0),
            'revenue_minor' => (int) ($byDate[$date]->revenue_minor ?? 0),
            'download_revenue_minor' => (int) ($byDate[$date]->download_revenue_minor ?? 0),
            'active_learners' => (int) ($byDate[$date]->active_learners ?? 0),
        ], $range->dates());
    }
}
