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
 * uses two endpoints — Checkout Sessions and Refunds; a dependency that pulls
 * in the whole API surface to reach two of them is not worth the upgrade
 * treadmill.
 *
 * The learner pays on Stripe's hosted Checkout page: the handoff is a
 * redirect, which the order page already follows, and no Stripe JS reaches
 * the SPA's first paint.
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

        $orderPage = rtrim(frontend_url(), '/').'/orders/'.$order->uuid;

        $response = Http::withToken($secretKey)
            ->asForm()
            ->post(self::API.'/checkout/sessions', [
                'mode' => 'payment',
                /*
                 * ONE line at the order's total, as priced here — coupons and
                 * all. Sending the order's own lines would let Stripe total
                 * them itself, and the figure that charges must be the one
                 * ADR-05 computed. Stripe speaks minor units too, so there is
                 * no conversion (ADR-04).
                 */
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => strtolower($order->currency),
                'line_items[0][price_data][unit_amount]' => $order->total_minor,
                'line_items[0][price_data][product_data][name]' => 'Order '.$order->number,
                // Our own id travels with the session and its payment, so either
                // can be tied back to the order from Stripe's side.
                'client_reference_id' => $order->uuid,
                'metadata[order_uuid]' => $order->uuid,
                'payment_intent_data[metadata][order_uuid]' => $order->uuid,
                // Where the learner lands. It proves nothing: the order page
                // polls until the webhook has spoken (ADR-05).
                'success_url' => $orderPage.'?paid=1',
                'cancel_url' => $orderPage,
                'expires_at' => now()->addMinutes($this->sessionMinutes())->getTimestamp(),
            ]);

        if ($response->failed()) {
            throw GatewayUnavailable::requestFailed(
                'stripe',
                (string) $response->json('error.message', 'Unknown error'),
            );
        }

        return new GatewayHandoff(
            // The session. checkout.session.* events name it; the PaymentIntent
            // behind it is learned at capture (`provider_payment_id`).
            externalId: (string) $response->json('id'),
            redirectUrl: (string) $response->json('url'),
        );
    }

    /**
     * How long the page stays payable: the coupon reservation window, so nobody
     * pays after the coupon use their order held has been given back
     * (CouponRules). Stripe allows 30 minutes to 24 hours, and 30 exactly can
     * lose to the clock, so the floor is 31 — a window configured below that
     * leaves the difference payable where CouponRules cannot see it.
     */
    private function sessionMinutes(): int
    {
        return min(max((int) config('orbito.coupons.reservation_minutes'), 31), 24 * 60);
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

        // The money, not the handoff: a Checkout payment's `external_id` is its
        // session, and Stripe refunds the PaymentIntent the session produced.
        $paymentIntent = $payment->provider_payment_id ?? $payment->external_id;

        if ($secretKey === '' || $paymentIntent === null) {
            throw GatewayUnavailable::notConfigured('stripe');
        }

        $response = Http::withToken($secretKey)
            ->asForm()
            // Stripe's own idempotency: the same key twice is one refund.
            ->withHeaders(['Idempotency-Key' => 'refund_'.$reference])
            ->post(self::API.'/refunds', [
                'payment_intent' => $paymentIntent,
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
        $isSession = is_array($object) && ($object['object'] ?? null) === 'checkout.session';

        // A refund names the payment it came off by its PaymentIntent — the
        // money, which HandleWebhook finds as `provider_payment_id` — never by
        // its own `re_…` id.
        $paymentKey = $isRefund ? 'payment_intent' : 'id';

        // A session reports its total as `amount_total` and has no `amount`. An
        // absent figure must never reach CapturePayment as "nothing to check".
        $amountKey = $isSession ? 'amount_total' : 'amount';

        return new WebhookEvent(
            id: $id,
            type: $type,
            externalPaymentId: is_array($object) && isset($object[$paymentKey]) && is_string($object[$paymentKey])
                ? $object[$paymentKey]
                : null,
            amountMinor: is_array($object) && isset($object[$amountKey]) && is_numeric($object[$amountKey])
                ? (int) $object[$amountKey]
                : null,
            currency: is_array($object) && isset($object['currency']) && is_string($object['currency'])
                ? strtoupper($object['currency'])
                : null,
            payload: $payload,
            refunds: $isRefund ? $this->refundsIn($object) : [],
            // The money behind a session, once there is some.
            providerPaymentId: $isSession && isset($object['payment_intent']) && is_string($object['payment_intent'])
                ? $object['payment_intent']
                : null,
            /*
             * A completed session is not always a paid one: a bank debit
             * completes first and pays — or fails — later, by the
             * async_payment_* events. Only `paid` grants (ADR-05).
             */
            settled: ! $isSession || ($object['payment_status'] ?? null) === 'paid',
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
