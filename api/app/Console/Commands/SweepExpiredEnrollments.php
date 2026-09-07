<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Events\EnrollmentExpired;
use App\Domain\Enrollment\Models\Enrollment;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Moves lapsed enrollments to `expired`.
 *
 * NOT load-bearing for correctness. `Enrollment::hasExpired()` is evaluated
 * live on every access check, so a learner is locked out the moment their date
 * passes whether or not this has run. This exists so the column agrees with
 * reality — for the students list, for counts, and so "expired" is a state the
 * instructor can filter on rather than something only the API knows.
 */
final class SweepExpiredEnrollments extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'enrollment:sweep-expired {--chunk=500}';

    protected $description = 'Mark enrollments whose access period has ended as expired';

    /**
     * `enrollments` lives in each academy's schema and the scheduler runs
     * centrally, so this has to walk the academies rather than issue one query.
     */
    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $swept = 0;

        $failed = $this->forEachTenant(function () use ($chunk, &$swept): void {
            $swept += $this->sweep($chunk);
        });

        $this->info("Expired {$swept} enrollment(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Runs inside one academy. */
    private function sweep(int $chunk): int
    {
        $swept = 0;

        Enrollment::query()
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            // The (status, expires_at) index makes this a range scan, not a
            // table walk, however many enrollments exist.
            ->orderBy('id')
            ->chunkById($chunk, function ($enrollments) use (&$swept): void {
                foreach ($enrollments as $enrollment) {
                    $enrollment->forceFill(['status' => EnrollmentStatus::Expired])->save();

                    EnrollmentExpired::dispatch($enrollment);
                    $swept++;
                }
            });

        return $swept;
    }
}
