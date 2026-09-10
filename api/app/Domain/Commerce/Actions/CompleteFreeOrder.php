<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;

/**
 * An order the SERVER priced at nothing — a 100% coupon, or a fixed one worth
 * more than the basket — is paid by definition.
 *
 * ADR-05 says access waits for a verified webhook because the client must
 * never be believed about MONEY. Here there is no money: the total was
 * computed from `product_prices` and the coupon, under a lock, and it is zero.
 * There is nothing for a gateway to confirm, and sending 0.00 to one is an
 * error at most providers, not a no-op. So it completes here, through the
 * same `GrantOrderAccess` a captured payment uses.
 *
 * No `PaymentCaptured`: nothing was captured, and revenue analytics and the
 * `payment.captured` webhook would both report a payment of nothing. The
 * enrolments it grants fire their own events.
 */
final class CompleteFreeOrder
{
    public function __construct(private readonly GrantOrderAccess $grant) {}

    public function handle(Order $order): Order
    {
        if (! $order->isFree() || ! $order->status->isPayable()) {
            return $order;
        }

        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

        $this->grant->handle($order);

        return $order->refresh();
    }
}
