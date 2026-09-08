<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\RecalculateCourseRating;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Recomputes every course's rating from its reviews.
 *
 * The counters are maintained by event; this is the safety net that turns
 * drift into something noticed rather than something lived with (CLAUDE.md
 * §10). A missed event, a direct database edit or a restored backup all leave
 * an average that is quietly wrong, and a wrong average on a course card is
 * invisible — nobody reports it, because nobody knows what it should be.
 *
 * Runs centrally with no academy open, so it must walk them (§16).
 */
final class ReconcileCourseRatings extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'ratings:reconcile';

    protected $description = 'Recompute course rating averages from reviews';

    public function handle(RecalculateCourseRating $recalculate): int
    {
        $drifted = 0;

        $failed = $this->forEachTenant(function () use ($recalculate, &$drifted): void {
            Course::query()->cursor()->each(function (Course $course) use ($recalculate, &$drifted): void {
                $before = [(int) $course->rating_count, (float) $course->rating_avg];

                $recalculate->handle($course);
                $course->refresh();

                $after = [(int) $course->rating_count, (float) $course->rating_avg];

                if ($before !== $after) {
                    $drifted++;
                    // Loud, because drift means an event was missed and the
                    // cause is worth finding, not just the symptom fixing.
                    $this->warn(sprintf(
                        'Course %d drifted: %d reviews @ %.2f -> %d @ %.2f',
                        $course->id,
                        $before[0], $before[1], $after[0], $after[1],
                    ));
                }
            });
        });

        $this->info($drifted === 0
            ? 'Every course rating already matched its reviews.'
            : "Corrected {$drifted} course rating(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
