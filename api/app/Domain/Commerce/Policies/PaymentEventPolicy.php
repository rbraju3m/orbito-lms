<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Policies;

use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Identity\Models\User;

/**
 * Refund reports the webhook left for a person (REFUNDS.md §6). `order.refund`
 * — Admin and Super Admin — because the person who can settle one is the
 * person who can refund: most end with a refund recorded or given again.
 */
final class PaymentEventPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('order.refund');
    }

    public function resolve(User $actor, PaymentEvent $event): bool
    {
        return $actor->hasPermission('order.refund');
    }
}
