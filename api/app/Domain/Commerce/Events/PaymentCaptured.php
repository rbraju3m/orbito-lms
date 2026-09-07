<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money confirmed by the provider. The one event other contexts may treat as
 * "this was actually paid for" — receipts, analytics, instructor earnings.
 */
final class PaymentCaptured
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly Order $order,
    ) {}
}
