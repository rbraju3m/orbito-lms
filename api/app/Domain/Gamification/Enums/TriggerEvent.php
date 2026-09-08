<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Enums;

/**
 * The things worth rewarding.
 *
 * DELIBERATELY NOT `Analytics\Enums\EventName`, even though the two overlap.
 * Analytics counts everything that happens; gamification rewards a much
 * smaller set, and the two lists have different reasons to change — a metric
 * added for a dashboard must not silently become a way to earn points, and a
 * name analytics retires must not silently stop paying out.
 *
 * Both are fed by the same DOMAIN events. Neither reads the other's table.
 */
enum TriggerEvent: string
{
    case ItemCompleted = 'item_completed';
    case CourseCompleted = 'course_completed';
    case QuizPassed = 'quiz_passed';
    case AssignmentGraded = 'assignment_graded';
    case ReviewPublished = 'review_published';
    case DiscussionAnswerAccepted = 'discussion_answer_accepted';

    public function label(): string
    {
        return match ($this) {
            self::ItemCompleted => 'Lesson completed',
            self::CourseCompleted => 'Course completed',
            self::QuizPassed => 'Quiz passed',
            self::AssignmentGraded => 'Assignment graded',
            self::ReviewPublished => 'Review published',
            self::DiscussionAnswerAccepted => 'Answer accepted',
        };
    }

    /**
     * Whether one learner can trigger this more than once for the same thing.
     *
     * Completing a lesson is once per lesson — the DEDUPE KEY makes that a
     * database constraint rather than a check. Grading is not: an assignment
     * handed back and re-marked genuinely happens twice, and refusing the
     * second would punish the learner for the instructor's workflow.
     */
    public function isOncePerSource(): bool
    {
        return match ($this) {
            self::AssignmentGraded => false,
            default => true,
        };
    }
}
