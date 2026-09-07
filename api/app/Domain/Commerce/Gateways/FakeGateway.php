<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

use App\Domain\Commerce\Data\GatewayHandoff;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Exceptions\WebhookRejected;
use App\Domain\Commerce\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A provider that behaves like a real one without a network.
 *
 * It exists so the money path can be PROVEN — signature verification,
 * idempotent replay, amount mismatch, forged success — rather than mocked
 * away. Its signature is a real HMAC over the raw body with the academy's own
 * secret, which is the same check Stripe's is; only the algorithm is simpler.
 *
 * PaymentGatewayFactory refuses to build it in production. That guard is the
 * only thing standing between a test convenience and free courses, so it is
 * enforced there rather than trusted here.
 */
final class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Fake-Signature';

    public function handoff(Order $order, GatewayAccount $account): GatewayHandoff
    {
        return new GatewayHandoff(
            externalId: 'fake_'.Str::lower(Str::random(24)),
            redirectUrl: null,
        );
    }

    public function verifyWebhook(Request $request, GatewayAccount $account): WebhookEvent
    {
        $raw = $request->getContent();
        $provided = (string) $request->header(self::SIGNATURE_HEADER, '');
        $expected = hash_hmac('sha256', $raw, $account->webhookSecret);

        // hash_equals, not ===: a timing-variable comparison on a signature is
        // a real oracle, and the cost of getting it right is one function name.
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw WebhookRejected::badSignature();
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true) ?: [];

        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            throw WebhookRejected::malformed();
        }

        return new WebhookEvent(
            id: $id,
            type: $type,
            externalPaymentId: isset($payload['payment_id']) && is_string($payload['payment_id'])
                ? $payload['payment_id']
                : null,
            amountMinor: isset($payload['amount_minor']) && is_numeric($payload['amount_minor'])
                ? (int) $payload['amount_minor']
                : null,
            currency: isset($payload['currency']) && is_string($payload['currency'])
                ? strtoupper($payload['currency'])
                : null,
            payload: $payload,
        );
    }
}
