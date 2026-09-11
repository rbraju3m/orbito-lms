<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * An order's life. Access is granted on becoming `paid`, which happens two
 * ways: `awaiting_payment → paid` by a verified webhook (ADR-05), or
 * `pending → paid` at checkout for an order the SERVER priced at zero — a
 * coupon took it to nothing, so there is no money to verify
 * (`CompleteFreeOrder`).
 *
 * A refund moves a paid order to `partially_refunded` or `refunded`
 * (`CompleteRefund`). Taking access away is a separate, explicit step
 * (`RevokeOrderAccess`), never a side effect of the status.
 */
enum OrderStatus: string
{
    /** Being built. No payment attempted. */
    case Pending = 'pending';
    /** Handed to a gateway. The learner may be on the gateway's page. */
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    /** Some of it given back. Still a sale: a coupon use on it still counts. */
    case PartiallyRefunded = 'partially_refunded';
    /** All of it given back. No longer a sale. */
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isPayable(): bool
    {
        return $this === self::Pending || $this === self::AwaitingPayment;
    }

    /** What the order page says. Access itself lives on the enrolments. */
    public function grantsAccess(): bool
    {
        return $this->countsAsSale();
    }

    /**
     * Money was taken and not all of it given back — THE definition of a sale,
     * read by coupon limits and anything else that counts sales, so a fully
     * refunded order stops counting everywhere at once.
     */
    public function countsAsSale(): bool
    {
        return $this === self::Paid || $this === self::PartiallyRefunded;
    }

    /** @return list<self> */
    public static function sales(): array
    {
        return [self::Paid, self::PartiallyRefunded];
    }

    public function isRefundable(): bool
    {
        return $this->countsAsSale();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not paid',
            self::AwaitingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partly refunded',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }
}
