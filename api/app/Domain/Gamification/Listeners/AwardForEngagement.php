<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Listeners;

use App\Domain\Engagement\Events\AnswerAccepted;
use App\Domain\Engagement\Events\ReviewPublished;
use App\Domain\Gamification\Actions\EvaluateTrigger;
use App\Domain\Gamification\Data\TriggerContext;
use App\Domain\Gamification\Enums\TriggerEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The half of gamification that is not a second progress bar.
 *
 * Reviewing a course and writing an answer somebody accepted are the only two
 * things here that reward doing something FOR OTHER PEOPLE. Without them the
 * whole apparatus measures the same thing the progress ring already shows.
 *
 * The reward for an accepted answer goes to whoever WROTE it, never to the
 * asker who marked it — otherwise the cheapest way to earn is to ask yourself
 * a question and answer it.
 */
final class AwardForEngagement implements ShouldQueue
{
    public function __construct(private readonly EvaluateTrigger $evaluate) {}

    public function review(ReviewPublished $event): void
    {
        $review = $event->review;

        $this->evaluate->handle(TriggerContext::for(
            TriggerEvent::ReviewPublished,
            $review->user_id,
            $review,
            ['rating' => $review->rating],
            $review->course_id,
        ));
    }

    public function answer(AnswerAccepted $event): void
    {
        $reply = $event->reply;

        $this->evaluate->handle(TriggerContext::for(
            TriggerEvent::DiscussionAnswerAccepted,
            // The author of the reply.
            $reply->user_id,
            $reply,
        ));
    }
}
