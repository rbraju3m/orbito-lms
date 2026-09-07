<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Actions\SubmitQuizAttempt;
use App\Domain\Assessment\Enums\AttemptStatus;
use App\Domain\Assessment\Models\QuizAttempt;
use Illuminate\Console\Command;

/**
 * Resolves attempts whose deadline passed while nobody was looking — a closed
 * tab, a dead battery, a client that never came back.
 *
 * Access and grading never DEPEND on this having run: expiry is evaluated live
 * on every request. This just stops abandoned attempts sitting open forever.
 */
final class SweepExpiredAttempts extends Command
{
    protected $signature = 'quiz:sweep-expired';

    protected $description = 'Resolve quiz attempts whose time limit has passed';

    public function handle(SubmitQuizAttempt $submit): int
    {
        $swept = 0;

        QuizAttempt::query()
            ->where('status', AttemptStatus::InProgress)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->cursor()
            ->each(function (QuizAttempt $attempt) use ($submit, &$swept): void {
                $submit->handle($attempt, viaSweeper: true);
                $swept++;
            });

        $this->components->info(
            $swept === 0 ? 'No expired attempts.' : "Resolved {$swept} expired attempt(s)."
        );

        return self::SUCCESS;
    }
}
