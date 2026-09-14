<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Domain\Analytics\Data\DateRange;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Analytics\Queries\CourseSeries;
use App\Domain\Analytics\Queries\FunnelQuery;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use App\Support\Http\CsvDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export, streamed through `CsvDownload` — which also defuses any cell a
 * spreadsheet would run as a formula, because every course title in these
 * files was typed by an instructor.
 */
final class AnalyticsExportController
{
    public function platform(Request $request): StreamedResponse
    {
        Gate::authorize('export-analytics');
        Gate::authorize('view-platform-analytics');

        $range = $this->range($request);

        $rows = DailyPlatformStat::query()
            ->whereBetween('date', [$range->from->toDateString(), $range->to->toDateString()])
            ->orderBy('date')
            ->cursor();

        return CsvDownload::stream(
            "orbito-platform-{$range->from->toDateString()}-to-{$range->to->toDateString()}.csv",
            ['date', 'new_users', 'new_enrollments', 'completions', 'revenue_minor', 'currency', 'active_learners'],
            (function () use ($rows): iterable {
                foreach ($rows as $row) {
                    yield [
                        $row->date->toDateString(),
                        $row->new_users,
                        $row->new_enrollments,
                        $row->completions,
                        // Minor units, with the code beside them. A
                        // spreadsheet that divides by 100 is the reader's
                        // decision; a float here would already have lost.
                        $row->revenue_minor,
                        $row->currency,
                        $row->active_learners,
                    ];
                }
            })(),
        );
    }

    public function courses(Request $request, CourseSeries $courses): StreamedResponse
    {
        Gate::authorize('export-analytics');

        $range = $this->range($request);

        /*
         * Scoped to what the caller may OPEN, not to a query parameter. An
         * export is the easiest place in an API to leak a whole academy's
         * revenue, because nobody reads a CSV expecting a permission error.
         */
        $rows = $courses->leaderboard($range, $this->visibleCourseIds($request->user()), limit: 1000);

        return CsvDownload::stream(
            "orbito-courses-{$range->from->toDateString()}-to-{$range->to->toDateString()}.csv",
            ['course', 'slug', 'views', 'enrollments', 'completions', 'revenue_minor'],
            $rows->map(fn (stdClass $row): array => [
                $row->title,
                $row->slug,
                $row->views,
                $row->enrollments,
                $row->completions,
                $row->revenue_minor,
            ]),
        );
    }

    public function funnel(Course $course, FunnelQuery $funnel): StreamedResponse
    {
        Gate::authorize('export-analytics');
        Gate::authorize('view-course-analytics', $course);

        return CsvDownload::stream(
            'orbito-funnel-'.$course->slug.'.csv',
            ['position', 'section', 'item', 'type', 'started', 'completed', 'drop_off_rate', 'avg_seconds'],
            $funnel->forCourse($course)->map(fn (stdClass $row): array => [
                $row->position,
                $row->section_title,
                $row->title,
                $row->type,
                $row->started,
                $row->completed,
                $row->drop_off_rate,
                $row->avg_seconds,
            ]),
        );
    }

    /**
     * Which courses this caller may see figures for.
     *
     * Null means every course — the platform reader. Anybody else gets the
     * ones they own or co-teach, resolved as ids because the alternative is a
     * policy call per row.
     *
     * @return list<int>|null
     */
    private function visibleCourseIds(User $user): ?array
    {
        if ($user->hasPermission('analytics.view.platform')) {
            return null;
        }

        return Course::query()
            ->where('owner_id', $user->id)
            ->orWhereHas('instructors', fn ($query) => $query->where('user_id', $user->id))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make(
            $request->query('from') === null ? null : (string) $request->query('from'),
            $request->query('to') === null ? null : (string) $request->query('to'),
        );
    }
}
