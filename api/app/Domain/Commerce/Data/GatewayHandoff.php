<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

/**
 * Where to send the learner, and what the provider called this attempt.
 *
 * `externalId` is stored on the payment BEFORE the learner leaves, so the
 * webhook that arrives later can be matched to an order without trusting
 * anything the browser carries back.
 */
final readonly class GatewayHandoff
{
    public function __construct(
        public string $externalId,
        /** Where to send the browser. Null for gateways that settle inline. */
        public ?string $redirectUrl = null,
        /** For SDK-driven flows (Stripe Elements) that need a token, not a URL. */
        public ?string $clientSecret = null,
    ) {}
}
