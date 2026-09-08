<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\RecalculateCourseRating;
use App\Domain\Engagement\Actions\RecalculateDiscussionCounters;
use App\Domain\Engagement\Models\Discussion;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Recomputes every stored engagement counter from the rows behind it —
 * course ratings from reviews, discussion reply counts from replies.
 *
 * The counters are maintained by event; this is the safety net that turns
 * drift into something noticed rather than something lived with (CLAUDE.md
 * §10). A missed event, a direct database edit or a restored backup all leave
 * an average that is quietly wrong, and a wrong average on a course card is
 * invisible — nobody reports it, because nobody knows what it should be.
 *
 * Runs centrally with no academy open, so it must walk them (§16).
 */
final class ReconcileEngagementCounters extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'engagement:reconcile';

    protected $description = 'Recompute course ratings and discussion counters from their rows';

    public function handle(
        RecalculateCourseRating $recalculate,
        RecalculateDiscussionCounters $counters,
    ): int {
        $drifted = 0;

        $failed = $this->forEachTenant(function () use ($recalculate, $counters, &$drifted): void {
            Discussion::query()->cursor()->each(function (Discussion $discussion) use ($counters, &$drifted): void {
                $before = [(int) $discussion->reply_count, $discussion->status->value];

                $counters->handle($discussion);
                $discussion->refresh();

                $after = [(int) $discussion->reply_count, $discussion->status->value];

                if ($before !== $after) {
                    $drifted++;
                    $this->warn(sprintf(
                        'Discussion %d drifted: %d replies (%s) -> %d (%s)',
                        $discussion->id,
                        $before[0], $before[1], $after[0], $after[1],
                    ));
                }
            });

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
            ? 'Every stored engagement counter already matched its rows.'
            : "Corrected {$drifted} drifted counter(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
