<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Events\CourseDeleted;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Certification\Events\CertificateIssued;
use App\Domain\Certification\Listeners\IssueCertificateOnCompletion;
use App\Domain\Certification\Listeners\RenderPdfOnIssue;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Listeners\RefreshCourseCurriculumCounters;
use App\Domain\Identity\Events\InstructorReviewed;
use App\Domain\Identity\Events\UserLoggedIn;
use App\Domain\Identity\Events\UserRegistered;
use App\Domain\Identity\Listeners\SendEmailVerification;
use App\Domain\Identity\Listeners\TouchLastSeen;
use App\Domain\Media\Events\MediaDeleted;
use App\Domain\Media\Events\MediaUploaded;
use App\Domain\Platform\Listeners\TrackCourseUsage;
use App\Domain\Platform\Listeners\TrackInstructorUsage;
use App\Domain\Platform\Listeners\TrackStorageUsage;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Listeners\RecountEnrollmentTotals;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The domain event catalogue. Cross-context reactions are wired here and
 * nowhere else, so the fan-out from any event is readable in one place.
 */
final class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string|array{0: class-string, 1: string}>> */
    private array $listen = [
        UserRegistered::class => [
            SendEmailVerification::class,
        ],
        UserLoggedIn::class => [
            TouchLastSeen::class,
        ],

        // Catalog and Media fire; Platform listens. Neither knows that billing
        // metrics exist — that is the dependency rule working as intended.
        CourseCreated::class => [
            [TrackCourseUsage::class, 'created'],
        ],
        CourseStatusChanged::class => [
            [TrackCourseUsage::class, 'statusChanged'],
        ],
        CourseDeleted::class => [
            [TrackCourseUsage::class, 'deleted'],
        ],
        MediaUploaded::class => [
            [TrackStorageUsage::class, 'uploaded'],
        ],
        MediaDeleted::class => [
            [TrackStorageUsage::class, 'deleted'],
        ],
        InstructorReviewed::class => [
            TrackInstructorUsage::class,
        ],

        // Curriculum fires; Catalog's denormalised counters follow. Progress
        // will join this list in Phase 6 to recount every enrollment.
        CurriculumChanged::class => [
            RefreshCourseCurriculumCounters::class,
            // Adding a lesson changes every enrolled learner's denominator.
            // Queued: 10,000 students must not make "add lesson" wait.
            RecountEnrollmentTotals::class,
        ],

        /*
         * Finishing a course issues a certificate, off the request — the exit
         * criterion for Phase 11 is that ticking the last lesson does not wait
         * on a mint and a PDF render.
         *
         * Progress does not know Certification exists; it fires and this map
         * connects them (CLAUDE.md §4).
         */
        CourseCompleted::class => [
            IssueCertificateOnCompletion::class,
        ],

        // Two listeners, not one action, because minting and rendering fail
        // differently: a failed render leaves a VALID certificate with no PDF.
        CertificateIssued::class => [
            RenderPdfOnIssue::class,
        ],
    ];

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
