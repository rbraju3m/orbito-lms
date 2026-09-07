<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * Where an academy's subscription stands.
 *
 * Enforcement is READ-ONLY: three of these still write, and none of them ever
 * stop an academy reading or exporting its own data. Locking a customer out of
 * their own courses is not leverage, it is how a lapsed account becomes a
 * former one.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    /** The grace window. Deliberately still writable. */
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /**
     * Whether the academy may still WRITE.
     *
     * The single source of this rule — the middleware asks, and never
     * re-derives it from dates.
     */
    public function permitsWrites(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Canceled, self::Expired => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Payment overdue',
            self::Canceled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
