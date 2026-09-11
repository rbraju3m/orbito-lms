<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

use App\Domain\Commerce\Data\GatewayHandoff;
use App\Domain\Commerce\Data\GatewayRefund;
use App\Domain\Commerce\Data\ProviderRefund;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Exceptions\GatewayUnavailable;
use App\Domain\Commerce\Exceptions\WebhookRejected;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe, against the REST API directly rather than the SDK.
 *
 * Two reasons. The credentials belong to the ACADEMY (ADR-13), so every call
 * has to carry a per-request key rather than a globally configured client —
 * which is the one thing the SDK's singleton shape makes awkward. And this
 * uses three endpoints; a dependency that pulls in the whole API surface to
 * reach three of them is not worth the upgrade treadmill.
 *
 * NOT VERIFIED AGAINST A REAL SANDBOX. Written to the documented API, and the
 * signature check below is Stripe's documented scheme, but no request here has
 * ever reached Stripe. Treat the first live run as the test.
 */
final class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    /** Stripe rejects a signature older than this, and so do we. */
    private const TOLERANCE_SECONDS = 300;

    /**
     * The credential holding the secret (or restricted) key — named by what
     * the Payments screen SAVES, not by what reads well here. This read
     * `secret_key` while the screen wrote `key`, so a Stripe account connected
     * through the product was "not configured" at every checkout, and no test
     * noticed: they all built the account directly. StripeCredentialsTest
     * connects it the screen's way.
     */
    private const KEY_CREDENTIAL = 'key';

    public function handoff(Order $order, GatewayAccount $account): GatewayHandoff
    {
        $secretKey = $account->credential(self::KEY_CREDENTIAL);

        if ($secretKey === '') {
            throw GatewayUnavailable::notConfigured('stripe');
        }

        $response = Http::withToken($secretKey)
            ->asForm()
            ->post(self::API.'/payment_intents', [
                // Stripe speaks minor units too, so there is no conversion
                // here — which is the point of storing them that way (ADR-04).
                'amount' => $order->total_minor,
                'currency' => strtolower($order->currency),
                // Our own id travels with the charge, so a webhook can be tied
                // back to an order even if our side lost the external id.
                'metadata[order_uuid]' => $order->uuid,
                'automatic_payment_methods[enabled]' => 'true',
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::requestFailed(
                'stripe',
                (string) $response->json('error.message', 'Unknown error'),
            );
        }

        return new GatewayHandoff(
            externalId: (string) $response->json('id'),
            // Stripe Elements takes a client secret, not a redirect.
            clientSecret: (string) $response->json('client_secret'),
        );
    }

    /**
     * ⚠ NOT VERIFIED AGAINST A REAL SANDBOX — the same caveat as the rest of
     * this class. `POST /v1/refunds` against the payment intent the handoff
     * created, carrying our refund's uuid as the idempotency key and as
     * `metadata[refund_uuid]`. Stripe reports `succeeded` for most card
     * refunds and `pending` for some methods; a pending one is settled by the
     * `refund.updated` webhook (ReconcileProviderRefund), matched on that
     * metadata.
     */
    public function refund(Payment $payment, int $amountMinor, string $reference, GatewayAccount $account): GatewayRefund
    {
        $secretKey = $account->credential(self::KEY_CREDENTIAL);

        if ($secretKey === '' || $payment->external_id === null) {
            throw GatewayUnavailable::notConfigured('stripe');
        }

        $response = Http::withToken($secretKey)
            ->asForm()
            // Stripe's own idempotency: the same key twice is one refund.
            ->withHeaders(['Idempotency-Key' => 'refund_'.$reference])
            ->post(self::API.'/refunds', [
                'payment_intent' => $payment->external_id,
                'amount' => $amountMinor,
                // Comes back on every refund webhook, so the report finds our
                // row before Stripe's `re_…` id has been stored on it.
                'metadata[refund_uuid]' => $reference,
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::requestFailed(
                'stripe',
                (string) $response->json('error.message', 'Unknown error'),
            );
        }

        return new GatewayRefund(
            externalId: (string) $response->json('id'),
            settled: $response->json('status') === 'succeeded',
        );
    }

    public function verifyWebhook(Request $request, GatewayAccount $account): WebhookEvent
    {
        $raw = $request->getContent();
        $header = (string) $request->header('Stripe-Signature', '');

        [$timestamp, $signatures] = $this->parseSignatureHeader($header);

        if ($timestamp === null || $signatures === []) {
            throw WebhookRejected::badSignature();
        }

        /*
         * The timestamp check is not decoration. Without it a signature stays
         * valid forever, and an attacker who captures one delivery can replay
         * it whenever they like — the idempotency key stops a duplicate, but
         * not a first delivery held back and used later.
         */
        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw WebhookRejected::badSignature();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $account->webhookSecret);

        $matched = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
            }
        }

        if (! $matched) {
            throw WebhookRejected::badSignature();
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true) ?: [];
        $object = $payload['data']['object'] ?? [];

        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;

        if (! is_string($id) || ! is_string($type)) {
            throw WebhookRejected::malformed();
        }

        $isRefund = is_array($object) && ($object['object'] ?? null) === 'refund';

        // A refund names the payment it came off by its PaymentIntent — the id
        // the handoff stored — never by its own `re_…` id.
        $paymentKey = $isRefund ? 'payment_intent' : 'id';

        return new WebhookEvent(
            id: $id,
            type: $type,
            externalPaymentId: is_array($object) && isset($object[$paymentKey]) && is_string($object[$paymentKey])
                ? $object[$paymentKey]
                : null,
            amountMinor: is_array($object) && isset($object['amount']) && is_numeric($object['amount'])
                ? (int) $object['amount']
                : null,
            currency: is_array($object) && isset($object['currency']) && is_string($object['currency'])
                ? strtoupper($object['currency'])
                : null,
            payload: $payload,
            refunds: $isRefund ? $this->refundsIn($object) : [],
        );
    }

    /**
     * A Stripe refund object, as a report. One missing a field this needs
     * reports nothing rather than a guess — a report is acted on, so a partial
     * one is worse than none.
     *
     * @param  array<mixed>  $object
     * @return list<ProviderRefund>
     */
    private function refundsIn(array $object): array
    {
        $id = $object['id'] ?? null;
        $amount = $object['amount'] ?? null;
        $currency = $object['currency'] ?? null;
        $status = match ($object['status'] ?? null) {
            'succeeded' => RefundStatus::Completed,
            'pending', 'requires_action' => RefundStatus::Pending,
            'failed', 'canceled' => RefundStatus::Failed,
            default => null,
        };

        if (! is_string($id) || ! is_int($amount) || ! is_string($currency) || $status === null) {
            return [];
        }

        $metadata = $object['metadata'] ?? null;
        $failure = $object['failure_reason'] ?? null;

        return [new ProviderRefund(
            externalId: $id,
            amountMinor: $amount,
            currency: strtoupper($currency),
            status: $status,
            reference: is_array($metadata) && isset($metadata['refund_uuid']) && is_string($metadata['refund_uuid'])
                ? $metadata['refund_uuid']
                : null,
            failureReason: is_string($failure) ? $failure : null,
        )];
    }

    /**
     * `t=1614556800,v1=abc...,v1=def...`
     *
     * More than one v1 is normal during a secret rotation, which is why this
     * returns a list and the caller checks them all rather than the first.
     *
     * @return array{0: int|null, 1: list<string>}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && is_numeric($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
