<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Analytics\Listeners\RecordAssessmentEvents;
use App\Domain\Analytics\Listeners\RecordCertificationEvents;
use App\Domain\Analytics\Listeners\RecordCommerceEvents;
use App\Domain\Analytics\Listeners\RecordEnrollmentEvents;
use App\Domain\Analytics\Listeners\RecordProgressEvents;
use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Assessment\Events\AssignmentSubmitted;
use App\Domain\Assessment\Events\QuizAttemptGraded;
use App\Domain\Assessment\Events\QuizAttemptSubmitted;
use App\Domain\Catalog\Events\BundleCreated;
use App\Domain\Catalog\Events\BundleDeleted;
use App\Domain\Catalog\Events\BundleStatusChanged;
use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Events\CourseDeleted;
use App\Domain\Catalog\Events\CoursePricingChanged;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Catalog\Events\DownloadCreated;
use App\Domain\Catalog\Events\DownloadDeleted;
use App\Domain\Catalog\Events\DownloadGranted;
use App\Domain\Catalog\Events\DownloadPricingChanged;
use App\Domain\Catalog\Events\DownloadStatusChanged;
use App\Domain\Catalog\Listeners\ReconcileBundleSellability;
use App\Domain\Certification\Events\CertificateIssued;
use App\Domain\Certification\Listeners\IssueCertificateOnCompletion;
use App\Domain\Certification\Listeners\RenderPdfOnIssue;
use App\Domain\Commerce\Events\PaymentCaptured;
use App\Domain\Commerce\Events\RefundIssued;
use App\Domain\Commerce\Listeners\SyncProductForPurchasable;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Listeners\RefreshCourseCurriculumCounters;
use App\Domain\Engagement\Events\AnnouncementPublished;
use App\Domain\Engagement\Events\AnswerAccepted;
use App\Domain\Engagement\Events\DiscussionReplied;
use App\Domain\Engagement\Events\QuestionAsked;
use App\Domain\Engagement\Events\ReviewChanged;
use App\Domain\Engagement\Events\ReviewPublished;
use App\Domain\Engagement\Listeners\RefreshCourseRating;
use App\Domain\Engagement\Listeners\RefreshDiscussionCounters;
use App\Domain\Engagement\Listeners\RemoveFromWishlistOnEnrollment;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Enrollment\Events\EnrollmentAccessChanged;
use App\Domain\Enrollment\Events\EnrollmentExpired;
use App\Domain\Enrollment\Events\EnrollmentReinstated;
use App\Domain\Enrollment\Events\EnrollmentRevoked;
use App\Domain\Enrollment\Events\EnrollmentSuspended;
use App\Domain\Gamification\Events\BadgeAwarded;
use App\Domain\Gamification\Events\PointsAwarded;
use App\Domain\Gamification\Events\StreakExtended;
use App\Domain\Gamification\Listeners\AwardForAssessment;
use App\Domain\Gamification\Listeners\AwardForEngagement;
use App\Domain\Gamification\Listeners\AwardForProgress;
use App\Domain\Gamification\Listeners\EvaluateBadges;
use App\Domain\Identity\Events\InstructorReviewed;
use App\Domain\Identity\Events\UserLoggedIn;
use App\Domain\Identity\Events\UserRegistered;
use App\Domain\Identity\Listeners\SendEmailVerification;
use App\Domain\Identity\Listeners\TouchLastSeen;
use App\Domain\Live\Events\AttendanceRecorded;
use App\Domain\Live\Events\WebinarCreated;
use App\Domain\Live\Events\WebinarDeleted;
use App\Domain\Live\Events\WebinarPricingChanged;
use App\Domain\Live\Events\WebinarStatusChanged;
use App\Domain\Live\Listeners\CompleteItemOnAttendance;
use App\Domain\Media\Events\MediaDeleted;
use App\Domain\Media\Events\MediaUploaded;
use App\Domain\Notification\Listeners\NotifyOnAnnouncementPublished;
use App\Domain\Notification\Listeners\NotifyOnAssignmentGraded;
use App\Domain\Notification\Listeners\NotifyOnBadgeAwarded;
use App\Domain\Notification\Listeners\NotifyOnCertificateIssued;
use App\Domain\Notification\Listeners\NotifyOnDiscussionReplied;
use App\Domain\Notification\Listeners\NotifyOnWebinarCancelled;
use App\Domain\Notification\Listeners\NotifyStaffOnQuestionAsked;
use App\Domain\Platform\Actions\EnsurePlatformOwner;
use App\Domain\Platform\Listeners\TrackCourseUsage;
use App\Domain\Platform\Listeners\TrackDownloadUsage;
use App\Domain\Platform\Listeners\TrackInstructorUsage;
use App\Domain\Platform\Listeners\TrackStorageUsage;
use App\Domain\Platform\Listeners\TrackStudentUsage;
use App\Domain\Progress\Events\CourseCompleted;
use App\Domain\Progress\Events\ItemCompleted;
use App\Domain\Progress\Listeners\RecountEnrollmentTotals;
use App\Domain\Webhook\Listeners\SendWebhooks;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
            [SyncProductForPurchasable::class, 'courseCreated'],
        ],
        CourseStatusChanged::class => [
            [TrackCourseUsage::class, 'statusChanged'],
            [SyncProductForPurchasable::class, 'courseStatusChanged'],
            // A course leaving `published` takes its bundles back to draft.
            ReconcileBundleSellability::class,
        ],

        /*
         * Catalog announces; Commerce decides whether there is anything to
         * sell (P16). `SyncCourseProduct` was written in P10 and wired to
         * NOTHING, so no product ever existed outside a factory — which meant
         * no course could be priced and every paid course was unpublishable.
         */
        CoursePricingChanged::class => [
            [SyncProductForPurchasable::class, 'coursePricingChanged'],
        ],
        BundleCreated::class => [
            [SyncProductForPurchasable::class, 'bundleCreated'],
        ],
        BundleStatusChanged::class => [
            [SyncProductForPurchasable::class, 'bundleStatusChanged'],
        ],
        // A deleted purchasable stops being sellable at once. See the listener.
        BundleDeleted::class => [
            [SyncProductForPurchasable::class, 'bundleDeleted'],
        ],

        /* Downloads (P16). Catalog announces; Commerce and Platform follow. */
        DownloadCreated::class => [
            [SyncProductForPurchasable::class, 'downloadCreated'],
            [TrackDownloadUsage::class, 'created'],
        ],
        DownloadStatusChanged::class => [
            [SyncProductForPurchasable::class, 'downloadStatusChanged'],
        ],
        DownloadPricingChanged::class => [
            [SyncProductForPurchasable::class, 'downloadPricingChanged'],
        ],
        DownloadDeleted::class => [
            [SyncProductForPurchasable::class, 'downloadDeleted'],
            [TrackDownloadUsage::class, 'deleted'],
        ],
        /*
         * Paid webinars (P16). The fourth purchasable, wired the same way —
         * the product exists from creation so the event can be priced while
         * it is still a draft, and is sellable only while it is published.
         */
        WebinarCreated::class => [
            [SyncProductForPurchasable::class, 'webinarCreated'],
        ],
        WebinarStatusChanged::class => [
            [SyncProductForPurchasable::class, 'webinarStatusChanged'],
            NotifyOnWebinarCancelled::class,
        ],
        WebinarPricingChanged::class => [
            [SyncProductForPurchasable::class, 'webinarPricingChanged'],
        ],
        WebinarDeleted::class => [
            [SyncProductForPurchasable::class, 'webinarDeleted'],
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

        /*
         * The student seat count. A seat is held by a PERSON, so what matters
         * is not which button was pressed but whether this enrolment started
         * or stopped granting access — which is the only thing
         * `EnrollmentAccessChanged` is fired for.
         */
        EnrollmentAccessChanged::class => [
            [TrackStudentUsage::class, 'accessChanged'],
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
            [RecordProgressEvents::class, 'course'],
            [AwardForProgress::class, 'course'],
        ],

        // Two listeners, not one action, because minting and rendering fail
        // differently: a failed render leaves a VALID certificate with no PDF.
        // The congratulation is a third, sent on ISSUE rather than on render,
        // so a font that fails to load is not also a missing notification.
        CertificateIssued::class => [
            RenderPdfOnIssue::class,
            NotifyOnCertificateIssued::class,
            RecordCertificationEvents::class,
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

        // The one event with a listener from each of the last two phases: a
        // learner is told, and the log records it.
        AssignmentGraded::class => [
            NotifyOnAssignmentGraded::class,
            [RecordAssessmentEvents::class, 'assignmentGraded'],
            [AwardForAssessment::class, 'assignment'],
        ],
        /*
         * Enrolling is the wish being granted, so the saved entry goes — a
         * wishlist of things you already have is noise. Queued: nothing about
         * the enrolment depends on it.
         */
        CourseEnrolled::class => [
            RemoveFromWishlistOnEnrollment::class,
            RecordEnrollmentEvents::class,
            [TrackStudentUsage::class, 'enrolled'],
        ],

        /*
         * The append-only log (ADR-08). Every listener here is queued and none
         * of them may throw into the request: analytics OBSERVES the system,
         * so a full disk must lose a row in a traffic count rather than break
         * somebody's lesson.
         *
         * Note what is NOT here. `course_viewed`, `item_started`,
         * `search_performed` and `cart_abandoned` have no domain event to hang
         * on — the server cannot see them — which is the entire reason
         * POST /analytics/track exists, and the reason its allowlist is
         * exactly those four.
         */
        ItemCompleted::class => [
            [RecordProgressEvents::class, 'item'],
            [AwardForProgress::class, 'item'],
        ],
        QuizAttemptSubmitted::class => [
            [RecordAssessmentEvents::class, 'quizSubmitted'],
        ],
        QuizAttemptGraded::class => [
            [RecordAssessmentEvents::class, 'quizGraded'],
            [AwardForAssessment::class, 'quiz'],
        ],
        AssignmentSubmitted::class => [
            [RecordAssessmentEvents::class, 'assignmentSubmitted'],
        ],
        PaymentCaptured::class => [
            RecordCommerceEvents::class,
        ],

        /*
         * Turning up completes the item. A live session is completable but
         * NOT self-markable, like a quiz and an assignment — the difference
         * is only what counts as earning it, and attendance is the evidence
         * every provider can produce.
         */
        AttendanceRecorded::class => [
            CompleteItemOnAttendance::class,
        ],

        /*
         * Gamification. Every listener queued: awarding takes a row lock on
         * the learner's profile, and ticking a checkbox must not wait on it.
         *
         * Note the SHAPE. The rule engine listens to the same domain events
         * analytics does and reads none of analytics' tables — two contexts
         * fed by one source, neither aware of the other. And badges listen to
         * gamification's OWN events rather than to the domain ones, so they
         * are evaluated exactly as often as a balance actually moved.
         */
        PointsAwarded::class => [
            [EvaluateBadges::class, 'points'],
        ],
        StreakExtended::class => [
            [EvaluateBadges::class, 'streak'],
        ],
        BadgeAwarded::class => [
            NotifyOnBadgeAwarded::class,
        ],
        ReviewPublished::class => [
            [AwardForEngagement::class, 'review'],
        ],
        AnswerAccepted::class => [
            [AwardForEngagement::class, 'answer'],
        ],
    ];

    /**
     * Outbound webhooks (ADR-12): one more listener per event, which is the
     * whole reason extension needs no plugin loader. Kept as its own map
     * rather than threaded through the one above, so "what can leave this
     * system" reads as one list — every entry here sends data to a third
     * party, and adding one is a decision about that, not a convenience.
     * Each event is one `WebhookTopic`; see docs/WEBHOOKS.md for the payloads.
     *
     * @var array<class-string, string>
     */
    private array $webhooks = [
        CourseEnrolled::class => 'enrolled',
        EnrollmentSuspended::class => 'suspended',
        EnrollmentReinstated::class => 'reinstated',
        EnrollmentRevoked::class => 'revoked',
        EnrollmentExpired::class => 'expired',
        ItemCompleted::class => 'itemCompleted',
        CourseCompleted::class => 'courseCompleted',
        CourseStatusChanged::class => 'courseStatusChanged',
        QuizAttemptGraded::class => 'quizGraded',
        AssignmentSubmitted::class => 'assignmentSubmitted',
        AssignmentGraded::class => 'assignmentGraded',
        PaymentCaptured::class => 'paymentCaptured',
        RefundIssued::class => 'refundIssued',
        CertificateIssued::class => 'certificateIssued',
        DownloadGranted::class => 'downloadGranted',
        ReviewPublished::class => 'reviewPublished',
    ];

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }

        foreach ($this->webhooks as $event => $method) {
            Event::listen($event, [SendWebhooks::class, $method]);
        }

        $this->ensurePlatformOwnerAfterMigrations();
    }

    /**
     * "The owner exists whenever the application runs" made concrete.
     *
     * A boot-time check would be a database write on the path of every
     * request; a seeder only runs when somebody asks. The honest hook is the
     * end of a migration, which is what every install and every deploy does.
     *
     * Three guards, each for a real case:
     *  - `tenancy()->initialized` — stancl migrates each academy's schema
     *    through this same event, and the owner is a CENTRAL row.
     *  - `up` — a rollback should not resurrect the account it just removed.
     *  - testing — the suite migrates once per process OUTSIDE a transaction,
     *    so a row written here would survive every rollback and skew any test
     *    that counts users. `PlatformOwnerTest` calls the Action directly.
     *
     * It never throws into the migration: a partial `migrate --path` run has
     * no `users` table, and a failed deploy is worse than a missing account
     * that the next `orbito:ensure-owner` creates anyway.
     *
     * One honest limit, verified rather than assumed: Laravel prints "Nothing
     * to migrate" and returns BEFORE firing this event, so a deploy that
     * carries no new migration does not reach here. That is harmless on an
     * installation that already has the owner and is exactly why
     * `orbito:ensure-owner` exists as a command — an existing installation
     * upgrading to this code needs one explicit run.
     */
    private function ensurePlatformOwnerAfterMigrations(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        Event::listen(function (MigrationsEnded $event): void {
            if ($event->method !== 'up' || tenancy()->initialized) {
                return;
            }

            try {
                app(EnsurePlatformOwner::class)->handle();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
