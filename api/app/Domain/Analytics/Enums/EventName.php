<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

/**
 * The canonical event vocabulary (docs/DATABASE.md §10).
 *
 * One name per thing worth counting. Names are past tense and describe what
 * happened, never what a dashboard wants — a metric is a question asked of
 * these later, and inventing `weekly_active_learner` as an EVENT would bake
 * one report's definition into the log forever.
 */
enum EventName: string
{
    /* -------------------------------------------------- raised by a client */

    case CourseViewed = 'course_viewed';
    case ItemStarted = 'item_started';
    case SearchPerformed = 'search_performed';
    case CartAbandoned = 'cart_abandoned';

    /* -------------------------------------- raised by the server, on events */

    case CourseEnrolled = 'course_enrolled';
    case ItemCompleted = 'item_completed';
    case QuizSubmitted = 'quiz_submitted';
    case QuizPassed = 'quiz_passed';
    case AssignmentSubmitted = 'assignment_submitted';
    case AssignmentGraded = 'assignment_graded';
    case CourseCompleted = 'course_completed';
    case CertificateIssued = 'certificate_issued';
    case PaymentCompleted = 'payment_completed';

    /**
     * May a browser raise this?
     *
     * THE SECURITY BOUNDARY OF THE WHOLE INGEST ENDPOINT. Everything below the
     * line above is a fact the server established — a completed payment, a
     * passed quiz, an issued certificate. A client that could post
     * `payment_completed` could write revenue into the dashboards without
     * paying anybody, and a course could be reported as completed by a learner
     * who opened one lesson.
     *
     * What a client MAY raise is exactly the set the server cannot see: a page
     * view, opening an item, a search, an abandoned basket. None of them
     * appear in a money or completion figure, so a liar only pollutes their
     * own traffic counts.
     */
    public function isClientRaisable(): bool
    {
        return match ($this) {
            self::CourseViewed, self::ItemStarted, self::SearchPerformed, self::CartAbandoned => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function clientRaisable(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->isClientRaisable()),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::CourseViewed => 'Course viewed',
            self::ItemStarted => 'Lesson opened',
            self::SearchPerformed => 'Search',
            self::CartAbandoned => 'Basket abandoned',
            self::CourseEnrolled => 'Enrolled',
            self::ItemCompleted => 'Lesson completed',
            self::QuizSubmitted => 'Quiz submitted',
            self::QuizPassed => 'Quiz passed',
            self::AssignmentSubmitted => 'Assignment submitted',
            self::AssignmentGraded => 'Assignment graded',
            self::CourseCompleted => 'Course completed',
            self::CertificateIssued => 'Certificate issued',
            self::PaymentCompleted => 'Payment completed',
        };
    }
}
