<?php

declare(strict_types=1);

use App\Domain\Gamification\Enums\BadgeTier;
use App\Domain\Gamification\Enums\TriggerEvent;

return [

    /*
    |--------------------------------------------------------------------------
    | Default rules
    |--------------------------------------------------------------------------
    |
    | Synced into every academy by `gamification:sync`, the same shape as
    | `permissions:sync`: this file is the SEED, not the source of truth. An
    | academy may retune points, deactivate a rule or add its own, and a
    | re-sync must not undo that — so the sync only creates what is missing.
    |
    | `points` are deliberately small and flat. A scheme where finishing one
    | course is worth more than a term of steady work teaches people to hunt
    | the big number, and the whole apparatus stops describing learning.
    */
    'rules' => [
        [
            'key' => 'lesson.completed',
            'event_name' => TriggerEvent::ItemCompleted->value,
            'name' => 'Finish a lesson',
            'points' => 10,
            'conditions' => [],
            // Once per lesson, enforced by the dedupe key — see the migration.
            'cooldown_seconds' => 0,
            // A ceiling on a repeatable-looking rule. Somebody clicking
            // through fifty short items in an afternoon is not fifty lessons
            // of learning, whatever the progress table says.
            'max_per_day' => 50,
        ],
        [
            'key' => 'course.completed',
            'event_name' => TriggerEvent::CourseCompleted->value,
            'name' => 'Finish a course',
            'points' => 200,
            'conditions' => [],
            'cooldown_seconds' => 0,
            'max_per_day' => 10,
        ],
        [
            'key' => 'quiz.passed',
            'event_name' => TriggerEvent::QuizPassed->value,
            'name' => 'Pass a quiz',
            'points' => 25,
            // Only a real pass, not a scrape. The condition is checked against
            // the trigger's payload, never against a model the rule re-reads.
            'conditions' => ['min_percent' => 60],
            'cooldown_seconds' => 0,
            'max_per_day' => 20,
        ],
        [
            'key' => 'assignment.passed',
            'event_name' => TriggerEvent::AssignmentGraded->value,
            'name' => 'Pass an assignment',
            'points' => 40,
            'conditions' => ['passed' => true],
            // Re-grading is a real thing, so this rule is not once-per-source.
            // The cooldown is what stops a grader's correction paying twice.
            'cooldown_seconds' => 86400,
            'max_per_day' => 10,
        ],
        [
            'key' => 'review.published',
            'event_name' => TriggerEvent::ReviewPublished->value,
            'name' => 'Review a course you took',
            'points' => 15,
            'conditions' => [],
            'cooldown_seconds' => 0,
            'max_per_day' => 5,
        ],
        [
            'key' => 'answer.accepted',
            'event_name' => TriggerEvent::DiscussionAnswerAccepted->value,
            'name' => 'Write an accepted answer',
            'points' => 30,
            'conditions' => [],
            'cooldown_seconds' => 0,
            'max_per_day' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default badges
    |--------------------------------------------------------------------------
    |
    | `criteria` is a CLOSED set of shapes, not an expression language. A DSL
    | that can express anything is a DSL nobody can debug at two in the
    | morning, and every shape here has to be answerable by one indexed query.
    | See BadgeCriteria for the list.
    */
    'badges' => [
        ['key' => 'first.lesson', 'name' => 'First step', 'tier' => BadgeTier::Bronze->value,
            'description' => 'Completed your first lesson.',
            'criteria' => ['type' => 'lessons_completed', 'threshold' => 1]],
        ['key' => 'lessons.25', 'name' => 'Getting going', 'tier' => BadgeTier::Bronze->value,
            'description' => 'Completed 25 lessons.',
            'criteria' => ['type' => 'lessons_completed', 'threshold' => 25]],
        ['key' => 'lessons.100', 'name' => 'Century', 'tier' => BadgeTier::Silver->value,
            'description' => 'Completed 100 lessons.',
            'criteria' => ['type' => 'lessons_completed', 'threshold' => 100]],

        ['key' => 'first.course', 'name' => 'Graduate', 'tier' => BadgeTier::Silver->value,
            'description' => 'Finished your first course.',
            'criteria' => ['type' => 'courses_completed', 'threshold' => 1]],
        ['key' => 'courses.5', 'name' => 'Scholar', 'tier' => BadgeTier::Gold->value,
            'description' => 'Finished five courses.',
            'criteria' => ['type' => 'courses_completed', 'threshold' => 5]],

        ['key' => 'streak.7', 'name' => 'Seven days', 'tier' => BadgeTier::Bronze->value,
            'description' => 'Learned on seven consecutive days.',
            'criteria' => ['type' => 'streak_days', 'threshold' => 7]],
        ['key' => 'streak.30', 'name' => 'Thirty days', 'tier' => BadgeTier::Gold->value,
            'description' => 'Learned on thirty consecutive days.',
            'criteria' => ['type' => 'streak_days', 'threshold' => 30]],

        ['key' => 'points.1000', 'name' => 'A thousand', 'tier' => BadgeTier::Silver->value,
            'description' => 'Earned a thousand points.',
            'criteria' => ['type' => 'points_total', 'threshold' => 1000]],

        ['key' => 'helper', 'name' => 'Helpful', 'tier' => BadgeTier::Silver->value,
            'description' => 'Five of your answers were accepted.',
            'criteria' => ['type' => 'answers_accepted', 'threshold' => 5]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaks
    |--------------------------------------------------------------------------
    |
    | A UTC day, for the same reason the analytics rollups use one: an academy
    | has no timezone of its own, and a streak keyed on a shifting local day
    | could not be recomputed deterministically. It means a streak rolls over
    | at an hour that is not midnight for most people, which is a real wart
    | and a smaller one than a counter nobody can rebuild.
    */
    'streaks' => [
        // What counts as "active today". Anything that awards points does.
        'grace_days' => 0,
    ],

    'leaderboard' => [
        'size' => 50,
    ],
];
