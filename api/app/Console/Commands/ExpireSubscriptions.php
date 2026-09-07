<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Subscription;
use Illuminate\Console\Command;

/**
 * The ONLY place a subscription's status degrades.
 *
 * Nothing recomputes lapse from dates on a read path, so an academy's ability
 * to write changes at a moment somebody can point at in a log rather than
 * silently between two requests while an instructor is mid-save.
 *
 * Entirely CENTRAL: `subscriptions` is a central table, so unlike the other
 * scheduled commands this one does not walk the academies.
 */
final class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Move lapsed subscriptions to past_due, then to expired once grace runs out';

    public function handle(): int
    {
        $toPastDue = 0;
        $toExpired = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) use (&$toPastDue, &$toExpired): void {
                foreach ($subscriptions as $subscription) {
                    $coverEnded = $subscription->coverEndsAt();

                    if ($coverEnded === null || $coverEnded->isFuture()) {
                        continue;
                    }

                    /*
                     * Grace runs from when cover ENDED, not from tonight. A
                     * sweep that missed three nights must not hand out three
                     * extra days — so a subscription lapsed longer ago than
                     * its grace window crosses BOTH cliffs in one pass and
                     * lands on expired, because that window is already spent.
                     */
                    if ($subscription->graceEndsAt()?->isPast() === true) {
                        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
                        $toExpired++;

                        continue;
                    }

                    if ($subscription->status !== SubscriptionStatus::PastDue) {
                        $subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();
                        $toPastDue++;
                    }
                }
            });

        $this->components->info("{$toPastDue} moved to past_due, {$toExpired} expired.");

        return self::SUCCESS;
    }
}
