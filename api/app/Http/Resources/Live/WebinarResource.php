<?php

declare(strict_types=1);

namespace App\Http\Resources\Live;

use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Support\WebinarPublishRules;
use App\Http\Resources\Catalog\PriceView;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A webinar, as its audience sees it — plus, for whoever may author it, what
 * they may DO with it.
 *
 * The authoring block is keyed on a flag the controller passes rather than on
 * a `when()` over the request, because each key is a rule somebody could
 * otherwise read as a fact about the event: `available_actions` is the
 * transition list the status enum enforces, `is_publishable` and
 * `publish_blockers` the content requirements `WebinarPublishRules` states,
 * `is_deletable` whether anybody has registered. None is a secret, but a
 * learner has no use for any of them (§ Patterns established in Phase 12).
 *
 * @mixin Webinar
 */
final class WebinarResource extends BaseResource
{
    /**
     * Memoised: `is_publishable` and `publish_blockers` ask the same question,
     * and the price rule inside it reads the product.
     *
     * @var list<array{code: string, field: string, message: string}>|null
     */
    private ?array $blockers = null;

    public function __construct(
        $resource,
        private readonly bool $isRegistered = false,
        private readonly bool $canManage = false,
        private readonly bool $canCancel = true,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = strtoupper((string) config('orbito.currency.base'));

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

            /*
             * What a place costs, in the shape the catalogue renders — null
             * for a free event and for one not on sale, which a draft's
             * product always is. It is a LABEL: the figure that charges is
             * the one `PlaceOrder` re-reads (ADR-05).
             */
            'price' => $this->when(
                $this->resource->relationLoaded('product'),
                fn () => PriceView::for($this->resource->product, $currency),
            ),

            'session' => $this->whenLoaded('session', fn () => $this->session === null ? null : [
                'id' => $this->session->uuid,
                'starts_at' => $this->session->starts_at->toIso8601String(),
                'ends_at' => $this->session->ends_at->toIso8601String(),
                'timezone' => $this->session->timezone,
                'status' => $this->session->currentStatus()->value,
            ]),

            // So the button says "Registered" rather than offering again.
            'is_registered' => $this->isRegistered,
            /*
             * Whether they may give the place up themselves. False for one
             * they BOUGHT: the cancel endpoint refuses it, because
             * re-registering at a paid event 423s and the way back in is the
             * one thing they cannot do. Giving it up is a refund.
             */
            'can_cancel' => $this->canCancel,
            /*
             * How many people are coming — LIVE registrations, which is what
             * `placesRemaining()` subtracts. `registrations_count` is the
             * other count and means something else: whether any record exists
             * at all, which is what makes a webinar undeletable.
             */
            'registration_count' => $this->whenCounted('registered'),

            ...($this->canManage ? [
                // The transitions ChangeWebinarStatus enforces, so a button
                // that would 409 cannot be rendered.
                'available_actions' => $this->status->availableTransitions(),
                /*
                 * From `WebinarPublishRules`, the class publishing enforces —
                 * and the reasons with it, because a disabled button with no
                 * explanation is the same dead end as a 423 that cannot say
                 * how to get in.
                 */
                'is_publishable' => $this->publishBlockers() === [],
                'publish_blockers' => $this->publishBlockers(),
                'is_deletable' => ! $this->isInUse(),
            ] : []),
        ];
    }

    /** @return list<array{code: string, field: string, message: string}> */
    private function publishBlockers(): array
    {
        return $this->blockers ??= app(WebinarPublishRules::class)->blockers($this->resource);
    }
}
