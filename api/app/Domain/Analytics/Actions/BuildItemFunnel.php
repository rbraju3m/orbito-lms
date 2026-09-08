<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Models\ItemFunnel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Progress\Models\ItemProgress;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Where a course loses people — the stall heatmap.
 *
 * THE ONE ROLLUP THAT DOES NOT READ THE EVENT LOG, and the exception is
 * deliberate. A funnel asks "of everybody who reached this item, how many got
 * past it?" — a question about the present state of every learner, not about a
 * day. `item_progress` already holds exactly that, one row per learner per
 * item, and the index on (course_item_id, status) was put there in Phase 6
 * for this. Replaying a year of log lines to rebuild a number that is already
 * a single GROUP BY would be slower and free to disagree with the player.
 *
 * `avg_seconds` reads `watch_max_seconds` — the FURTHEST point reached, not
 * the last position — so somebody who scrubbed back to re-watch a passage
 * does not read as having watched less.
 */
final class BuildItemFunnel
{
    public function handle(?Course $course = null): int
    {
        $rows = ItemProgress::query()
            ->when($course !== null, fn ($query) => $query->where('course_id', $course->id))
            ->groupBy('course_item_id', 'course_id')
            ->selectRaw('course_item_id, course_id')
            // Started means seen at all. A row exists from first view (§12),
            // so its presence IS the start.
            ->selectRaw('COUNT(*) as started')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            /*
             * Null, not zero, when nobody watched anything. "Nobody has opened
             * this" and "everybody left immediately" are different facts, and
             * a text lesson has no seconds to average at all.
             */
            ->selectRaw('NULLIF(AVG(NULLIF(watch_max_seconds, 0)), 0) as avg_seconds')
            // Aggregate rows, not ItemProgress models — see BuildDailyRollups.
            ->toBase()
            ->get();

        $now = now();

        if ($rows->isNotEmpty()) {
            $this->write($rows, $now);
        }

        $this->dropStale(
            $rows->map(fn (stdClass $row): int => (int) $row->course_item_id)->values()->all(),
            $course,
        );

        return $rows->count();
    }

    /**
     * @param  Collection<int, stdClass>  $rows  Aggregate rows, not models.
     */
    private function write(Collection $rows, CarbonInterface $now): void
    {
        ItemFunnel::query()->upsert(
            $rows->map(function (stdClass $row) use ($now): array {
                $started = (int) $row->started;
                $completed = (int) $row->completed;

                return [
                    'course_item_id' => (int) $row->course_item_id,
                    'course_id' => (int) $row->course_id,
                    'started' => $started,
                    'completed' => $completed,
                    'avg_seconds' => $row->avg_seconds === null ? null : (int) round((float) $row->avg_seconds),
                    // Stored rather than derived on read, so ORDER BY finds
                    // the worst item in a course without a computed column.
                    'drop_off_rate' => $started === 0 ? 0 : round(($started - $completed) / $started, 4),
                    'computed_at' => $now,
                ];
            })->all(),
            ['course_item_id'],
            ['course_id', 'started', 'completed', 'avg_seconds', 'drop_off_rate', 'computed_at'],
        );
    }

    /**
     * An item nobody has progress on any more — the course was restructured,
     * the item deleted and recreated — would otherwise keep a stale row
     * forever, because an upsert only touches what it writes. It runs even
     * when there is nothing to write, which is exactly the case where every
     * remaining row IS stale.
     *
     * @param  list<int>  $keep
     */
    private function dropStale(array $keep, ?Course $course): void
    {
        ItemFunnel::query()
            ->when($course !== null, fn ($query) => $query->where('course_id', $course->id))
            ->whereNotIn('course_item_id', $keep)
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('item_progress')
                ->whereColumn('item_progress.course_item_id', 'analytics_item_funnel.course_item_id'))
            ->delete();
    }
}
