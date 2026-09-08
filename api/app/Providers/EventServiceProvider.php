<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Events\CourseDeleted;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Certification\Events\CertificateIssued;
use App\Domain\Certification\Listeners\IssueCertificateOnCompletion;
use App\Domain\Certification\Listeners\RenderPdfOnIssue;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Listeners\RefreshCourseCurriculumCounters;
use App\Domain\Engagement\Events\AnnouncementPublished;
use App\Domain\Engagement\Events\DiscussionReplied;
use App\Domain\Engagement\Events\QuestionAsked;
use App\Domain\Engagement\Events\ReviewChanged;
use App\Domain\Engagement\Listeners\RefreshCourseRating;
use App\Domain\Engagement\Listeners\RefreshDiscussionCounters;
use App\Domain\Engagement\Listeners\RemoveFromWishlistOnEnrollment;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Identity\Events\InstructorReviewed;
use App\Domain\Identity\Events\UserLoggedIn;
use App\Domain\Identity\Events\UserRegistered;
use App\Domain\Identity\Listeners\SendEmailVerification;
use App\Domain\Identity\Listeners\TouchLastSeen;
use App\Domain\Media\Events\MediaDeleted;
use App\Domain\Media\Events\MediaUploaded;
use App\Domain\Notification\Listeners\NotifyOnAnnouncementPublished;
use App\Domain\Notification\Listeners\NotifyOnAssignmentGraded;
use App\Domain\Notification\Listeners\NotifyOnCertificateIssued;
use App\Domain\Notification\Listeners\NotifyOnDiscussionReplied;
use App\Domain\Notification\Listeners\NotifyStaffOnQuestionAsked;
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
        // The congratulation is a third, sent on ISSUE rather than on render,
        // so a font that fails to load is not also a missing notification.
        CertificateIssued::class => [
            RenderPdfOnIssue::class,
            NotifyOnCertificateIssued::class,
        ],

        /*
         * The Phase 12 exit criterion: rating averages are COLUMNS, never
         * AVG() on a card. Not queued — a learner who publishes a review and
         * still sees the old average will assume the write failed, and the
         * work is one indexed aggregate over one course.
         */
        ReviewChanged::class => [
            RefreshCourseRating::class,
        ],

        // Same shape, same reason: a Q&A list renders dozens of threads and
        // "how many replies?" must not be a subquery per row.
        DiscussionReplied::class => [
            RefreshDiscussionCounters::class,
            // The two people actually addressed — the asker and whoever this
            // reply hangs under. Queued; the counter refresh is not.
            NotifyOnDiscussionReplied::class,
        ],

        /*
         * Notifications. Every listener below is QUEUED, without exception:
         * each of them can address thousands of people and each of them can
         * send mail, and neither belongs on the request that caused it.
         *
         * Preferences are NOT consulted here. DomainNotification::via() is
         * the single enforcement point, so a delivery raised from anywhere —
         * a command, a future digest — obeys the switches without having to
         * remember to ask.
         */
        AnnouncementPublished::class => [
            NotifyOnAnnouncementPublished::class,
        ],
        QuestionAsked::class => [
            NotifyStaffOnQuestionAsked::class,
        ],
        AssignmentGraded::class => [
            NotifyOnAssignmentGraded::class,
        ],

        /*
         * Enrolling is the wish being granted, so the saved entry goes — a
         * wishlist of things you already have is noise. Queued: nothing about
         * the enrolment depends on it.
         */
        CourseEnrolled::class => [
            RemoveFromWishlistOnEnrollment::class,
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
