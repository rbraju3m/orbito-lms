<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Events\WebinarStatusChanged;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Support\WebinarPublishRules;

/**
 * Every lifecycle move a webinar makes — publish, unpublish, cancel, revive.
 *
 * One place owns the legal transitions, the same shape `ChangeCourseStatus`
 * owns a course's, and `WebinarStatus::allows()` is what it reads — the list
 * the resource renders as `available_actions`, so the button and the server
 * cannot disagree.
 *
 * Cancelling does NOT touch the registrations. A place held is a record of
 * who was coming, and an academy that calls an event off still needs to know
 * who to tell.
 */
final class ChangeWebinarStatus
{
    public function __construct(private readonly WebinarPublishRules $rules) {}

    public function handle(Webinar $webinar, WebinarStatus $target, User $actor): Webinar
    {
        $from = $webinar->status;

        if ($from === $target) {
            return $webinar;
        }

        if (! $from->allows($target)) {
            throw LiveSessionRejected::webinarTransitionRejected(
                $from->value,
                $target->value,
                $from->availableTransitions(),
            );
        }

        /*
         * The content requirements, and neither is a formality: a published
         * webinar is offered for registration, and one with no session has
         * nothing to attend while one marked paid with no price has nothing
         * to charge. Read from `WebinarPublishRules`, the same class the
         * resource renders, so the disabled button and this refusal cannot
         * disagree.
         */
        if ($target === WebinarStatus::Published) {
            $blockers = $this->rules->blockers($webinar);

            if ($blockers !== []) {
                throw LiveSessionRejected::webinarNotPublishable($blockers);
            }
        }

        $webinar->forceFill(['status' => $target])->save();

        WebinarStatusChanged::dispatch($webinar, $from, $target, $actor->id);

        return $webinar->refresh();
    }
}
