<?php

declare(strict_types=1);

namespace App\Http\Resources\Live;

use App\Domain\Live\Models\Webinar;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin Webinar
 */
final class WebinarResource extends BaseResource
{
    public function __construct($resource, private readonly bool $isRegistered = false)
    {
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
        ];
    }
}
