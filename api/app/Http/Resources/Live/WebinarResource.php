<?php

declare(strict_types=1);

namespace App\Http\Resources\Live;

use App\Domain\Live\Models\Webinar;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A webinar, as its audience sees it — plus, for whoever may author it, what
 * they may DO with it.
 *
 * The authoring block is keyed on a flag the controller passes rather than on
 * a `when()` over the request, because each key is a rule somebody could
 * otherwise read as a fact about the event: `available_actions` is the
 * transition list the status enum enforces, `is_publishable` the one content
 * requirement, `is_deletable` whether anybody has registered. None is a
 * secret, but a learner has no use for any of them (§ Patterns established in
 * Phase 12).
 *
 * @mixin Webinar
 */
final class WebinarResource extends BaseResource
{
    public function __construct(
        $resource,
        private readonly bool $isRegistered = false,
        private readonly bool $canManage = false,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'capacity' => $this->capacity,
            'places_remaining' => $this->placesRemaining(),
            'is_paid' => $this->is_paid,

            'session' => $this->whenLoaded('session', fn () => $this->session === null ? null : [
                'id' => $this->session->uuid,
                'starts_at' => $this->session->starts_at->toIso8601String(),
                'ends_at' => $this->session->ends_at->toIso8601String(),
                'timezone' => $this->session->timezone,
                'status' => $this->session->currentStatus()->value,
            ]),

            // So the button says "Registered" rather than offering again.
            'is_registered' => $this->isRegistered,
            'registration_count' => $this->whenCounted('registrations'),

            ...($this->canManage ? [
                // The transitions ChangeWebinarStatus enforces, so a button
                // that would 409 cannot be rendered.
                'available_actions' => $this->status->availableTransitions(),
                'is_publishable' => $this->live_session_id !== null,
                'is_deletable' => ! $this->isInUse(),
            ] : []),
        ];
    }
}
