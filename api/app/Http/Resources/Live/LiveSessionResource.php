<?php

declare(strict_types=1);

namespace App\Http\Resources\Live;

use App\Domain\Live\Models\LiveSession;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One session, as a learner or a host sees it.
 *
 * `host_url` IS NEVER HERE. On Zoom the start link opens the meeting as the
 * host; the model hides it and this resource does not name it, so there is no
 * single mistake that hands a learner the room.
 *
 * `join_url` is present only while the session is JOINABLE and only to
 * somebody in the audience — the controller decides who, and passes it in.
 * A link rendered on a page for an hour before the class is a link that ends
 * up in a group chat.
 *
 * @mixin LiveSession
 */
final class LiveSessionResource extends BaseResource
{
    public function __construct($resource, private readonly bool $canJoin = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->currentStatus();

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,

            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),

            /*
             * Derived from the clock on every read, never swept — the same
             * reasoning as drip and sale prices. A status that needed a cron
             * to become true would be wrong exactly when somebody is trying
             * to join.
             */
            'status' => $status->value,
            'status_label' => $status->label(),

            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            // The zone it was SCHEDULED in, so the UI can say "7pm Dhaka" as
            // well as the reader's own time.
            'timezone' => $this->timezone,

            'host' => [
                'name' => $this->whenLoaded('host', fn () => $this->host->name ?? 'Former member'),
            ],

            'course' => $this->whenLoaded('course', fn () => $this->course === null ? null : [
                'id' => $this->course->uuid,
                'title' => $this->course->title,
            ]),

            'cohort' => $this->whenLoaded('cohort', fn () => $this->cohort === null ? null : [
                'id' => $this->cohort->uuid,
                'name' => $this->cohort->name,
            ]),

            // Absent unless they may actually follow it, right now.
            'join_url' => $this->canJoin && $status->isJoinable() ? $this->join_url : null,
            'can_join' => $this->canJoin && $status->isJoinable() && $this->join_url !== null,

            // A session with no link yet is a placeholder the author has not
            // finished. Said plainly rather than rendered as a dead button.
            'has_link' => $this->join_url !== null,

            'recording_url' => $this->whenLoaded(
                'recording',
                fn () => $this->recording?->publicUrl(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
