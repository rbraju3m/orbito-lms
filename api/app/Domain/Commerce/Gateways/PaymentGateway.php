<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

use App\Domain\Commerce\Data\GatewayHandoff;
use App\Domain\Commerce\Data\GatewayRefund;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use Illuminate\Http\Request;

/**
 * The seam every payment provider fits behind.
 *
 * Two methods, because ADR-05 only needs two moments: handing a priced order
 * to the provider, and being told — verifiably — what happened. Notice what is
 * absent: there is no `confirm()` the client can call. A redirect back from
 * the provider proves nothing, and a gateway that offered one would invite
 * exactly the forged success this design refuses.
 *
 * Implementations are resolved per academy through PaymentGatewayFactory,
 * because each academy connects its own account (ADR-13).
 */
interface PaymentGateway
{
    /**
     * Hand a priced order to the provider and get back where to send the
     * learner. Must not mutate the order.
     */
    public function handoff(Order $order, GatewayAccount $account): GatewayHandoff;

    /**
     * Turn a raw inbound request into a verified event.
     *
     * MUST verify the signature against the academy's own webhook secret and
     * throw on failure. Returning an unverified event would make every later
     * check pointless, because the payload would be attacker-controlled.
     */
    public function verifyWebhook(Request $request, GatewayAccount $account): WebhookEvent;

    /**
     * Give money back on a captured payment — all of it or part of it.
     *
     * MUST throw (GatewayUnavailable) when the provider refuses, so the refund
     * is marked failed and its amount freed, never completed. The idempotency
     * key is the refund's own id: a retried request after a timeout must not
     * give the money back twice.
     */
    public function refund(Payment $payment, int $amountMinor, string $idempotencyKey, GatewayAccount $account): GatewayRefund;
}
