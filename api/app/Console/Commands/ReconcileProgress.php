<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Progress\Actions\RecalculateCourseProgress;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;
use Illuminate\Console\Command;

/**
 * The stored aggregate can only drift if a listener dies mid-flight, so drift
 * greater than zero is a BUG ALERT, not routine maintenance (ADR-02).
 */
final class ReconcileProgress extends Command
{
    protected $signature = 'progress:reconcile {--dry-run : Report drift without correcting it}';

    protected $description = 'Recompute stored course progress from item progress and report drift';

    public function handle(RecalculateCourseProgress $recalculate): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        // Totals are per COURSE, not per enrollment, so compute them once.
        $totals = [];

        Enrollment::query()->with('progress')->cursor()->each(
            function (Enrollment $enrollment) use ($recalculate, $dryRun, &$rows, &$totals): void {
                $total = $totals[$enrollment->course_id] ??= CourseItem::query()
                    ->where('course_id', $enrollment->course_id)
                    ->published()
                    ->whereNot('type', 'resource')
                    ->count();

                $completed = ItemProgress::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->where('status', ItemProgressStatus::Completed)
                    ->whereIn('course_item_id', CourseItem::query()
                        ->where('course_id', $enrollment->course_id)
                        ->published()
                        ->whereNot('type', 'resource')
                        ->select('id'))
                    ->count();

                $stored = $enrollment->progress;

                if ($stored !== null
                    && $stored->completed_items === $completed
                    && $stored->total_items === $total) {
                    return;
                }

                $rows[] = [
                    $enrollment->uuid,
                    $stored === null ? 'missing' : "{$stored->completed_items}/{$stored->total_items}",
                    "{$completed}/{$total}",
                ];

                if (! $dryRun) {
                    $recalculate->handle($enrollment);
                }
            }
        );

        if ($rows === []) {
            $this->components->info('Course progress is consistent.');

            return self::SUCCESS;
        }

        $this->table(['Enrollment', 'Stored', 'Actual'], $rows);
        $this->components->warn(sprintf(
            '%d enrollment(s) drifted%s',
            count($rows),
            $dryRun ? ' (dry run, nothing changed).' : ' and were corrected.',
        ));

        return self::SUCCESS;
    }
}
