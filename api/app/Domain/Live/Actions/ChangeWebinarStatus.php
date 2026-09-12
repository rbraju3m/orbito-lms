<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Events\WebinarStatusChanged;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Webinar;

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
         * The one content requirement, and it is not a formality: a published
         * webinar is offered for registration, and a webinar with no session
         * has no time, no link and nothing to attend.
         */
        if ($target === WebinarStatus::Published && $webinar->live_session_id === null) {
            throw LiveSessionRejected::webinarNeedsSession();
        }

        $webinar->forceFill(['status' => $target])->save();

        WebinarStatusChanged::dispatch($webinar, $from, $target, $actor->id);

        return $webinar->refresh();
    }
}
