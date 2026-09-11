<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Policies;

use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Models\User;

/**
 * Who may see and pay an order.
 *
 * `order.view.own` is not consulted for the owner's own order. Every learner
 * holds it, and an order is the record of a transaction the caller themselves
 * made — gating it behind a permission an academy could revoke would let an
 * academy hide from a learner what that learner was charged.
 */
final class OrderPolicy
{
    public function view(User $actor, Order $order): bool
    {
        return $order->user_id === $actor->id
            || $actor->hasPermission('order.view.any');
    }

    /**
     * Paying is the OWNER's act and nobody else's.
     *
     * Not even `order.view.any` staff: starting a payment creates a payment
     * row and hands the learner's order to a provider. Staff who need to
     * settle something by hand grant a seat (`EnrollmentIntent::manual`),
     * which is an honest record of what happened rather than a payment
     * nobody made.
     */
    public function pay(User $actor, Order $order): bool
    {
        return $order->user_id === $actor->id;
    }

    /**
     * Staff with `order.refund` (Admin, Super Admin) — and NOT the learner,
     * even on their own order. A learner asks; the academy decides.
     */
    public function refund(User $actor, Order $order): bool
    {
        return $actor->hasPermission('order.refund');
    }
}
