<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Enums;

/**
 * What an endpoint can subscribe to — the PUBLIC names of domain events.
 *
 * Each case is exactly one event in docs/EVENTS.md, under a dotted name an
 * integrator can read (ADR-12: webhooks subscribe to the catalogue and invent
 * no vocabulary of their own). The value is a contract with systems we do not
 * control: rename a case and every receiver filtering on it breaks silently,
 * so a value is added, never changed.
 *
 * Deliberately not everything in the catalogue. `CurriculumChanged`,
 * `MediaUploaded` and `PointsAwarded` are internal plumbing; `UserLoggedIn`
 * would stream everybody's sessions to a third party. A topic is added when
 * somebody has a use for it, and its payload is documented when it is.
 */
enum WebhookTopic: string
{
    case EnrollmentCreated = 'enrollment.created';
    case EnrollmentSuspended = 'enrollment.suspended';
    case EnrollmentReinstated = 'enrollment.reinstated';
    case EnrollmentRevoked = 'enrollment.revoked';
    case EnrollmentExpired = 'enrollment.expired';

    case ItemCompleted = 'item.completed';
    case CourseCompleted = 'course.completed';
    case CourseStatusChanged = 'course.status_changed';

    case QuizGraded = 'quiz.graded';
    case AssignmentSubmitted = 'assignment.submitted';
    case AssignmentGraded = 'assignment.graded';

    case PaymentCaptured = 'payment.captured';
    case CertificateIssued = 'certificate.issued';
    case DownloadGranted = 'download.granted';
    case ReviewPublished = 'review.published';

    /* Sent by "send test event" only. Nobody subscribes to it. */
    case Ping = 'ping';

    public function label(): string
    {
        return match ($this) {
            self::EnrollmentCreated => 'Enrolled',
            self::EnrollmentSuspended => 'Enrolment suspended',
            self::EnrollmentReinstated => 'Enrolment reinstated',
            self::EnrollmentRevoked => 'Enrolment revoked',
            self::EnrollmentExpired => 'Enrolment expired',
            self::ItemCompleted => 'Lesson or item completed',
            self::CourseCompleted => 'Course completed',
            self::CourseStatusChanged => 'Course status changed',
            self::QuizGraded => 'Quiz graded',
            self::AssignmentSubmitted => 'Assignment submitted',
            self::AssignmentGraded => 'Assignment graded',
            self::PaymentCaptured => 'Payment captured',
            self::CertificateIssued => 'Certificate issued',
            self::DownloadGranted => 'Download granted',
            self::ReviewPublished => 'Review published',
            self::Ping => 'Test event',
        };
    }

    /** How the picker groups topics. */
    public function group(): string
    {
        return match ($this) {
            self::EnrollmentCreated, self::EnrollmentSuspended, self::EnrollmentReinstated,
            self::EnrollmentRevoked, self::EnrollmentExpired => 'Enrolment',
            self::ItemCompleted, self::CourseCompleted => 'Progress',
            self::QuizGraded, self::AssignmentSubmitted, self::AssignmentGraded => 'Assessment',
            self::PaymentCaptured, self::DownloadGranted => 'Commerce',
            self::CertificateIssued => 'Certification',
            self::CourseStatusChanged => 'Catalogue',
            self::ReviewPublished => 'Engagement',
            self::Ping => 'Testing',
        };
    }

    public function isSubscribable(): bool
    {
        return $this !== self::Ping;
    }

    /** @return list<string> */
    public static function subscribable(): array
    {
        return array_values(array_map(
            static fn (self $topic): string => $topic->value,
            array_filter(self::cases(), static fn (self $topic): bool => $topic->isSubscribable()),
        ));
    }
}
