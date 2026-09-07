<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

/**
 * A webhook the gateway has already proven it sent.
 *
 * Only constructed by an implementation that verified the signature — which is
 * why nothing downstream re-checks it, and why `verifyWebhook()` throws rather
 * than returning an "unverified" variant somebody could forget to test.
 */
final readonly class WebhookEvent
{
    public function __construct(
        /** The provider's own event id. The idempotency key. */
        public string $id,
        public string $type,
        /** The provider's payment id, matching GatewayHandoff::$externalId. */
        public ?string $externalPaymentId,
        /** Minor units, as the provider reports them. Checked against the order. */
        public ?int $amountMinor,
        public ?string $currency,
        /** @var array<string, mixed> */
        public array $payload,
    ) {}

    public function isSuccess(): bool
    {
        return in_array($this->type, [
            'payment.captured',
            'payment_intent.succeeded',
            'checkout.session.completed',
        ], true);
    }

    public function isFailure(): bool
    {
        return in_array($this->type, [
            'payment.failed',
            'payment_intent.payment_failed',
        ], true);
    }
}
