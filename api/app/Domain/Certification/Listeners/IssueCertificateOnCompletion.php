<?php

declare(strict_types=1);

namespace App\Domain\Certification\Listeners;

use App\Domain\Certification\Actions\IssueCertificate;
use App\Domain\Certification\Exceptions\CertificateRejected;
use App\Domain\Progress\Events\CourseCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Completing a course issues a certificate — off the request.
 *
 * Queued because the exit criterion says so: a learner who ticks the last
 * lesson must not wait on a mint, a template read and a PDF render before
 * their click returns. Progress is the thing that happened; the certificate
 * is a consequence.
 *
 * A course that does not award certificates is not an error. `CertificateRejected`
 * is the normal answer for most courses, so it is swallowed rather than
 * failing a job and retrying forever.
 */
final class IssueCertificateOnCompletion implements ShouldQueue
{
    public function __construct(private readonly IssueCertificate $issue) {}

    public function handle(CourseCompleted $event): void
    {
        try {
            $this->issue->handle($event->enrollment);
        } catch (CertificateRejected $e) {
            // Expected for any course with certificates switched off. Logged
            // at debug so it is findable without being noise.
            Log::debug('No certificate issued for a completed course.', [
                'enrollment' => $event->enrollment->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
