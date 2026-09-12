<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Models\LiveProviderAccount;

/**
 * Forgets an academy's credentials for one provider.
 *
 * The row is DELETED rather than deactivated, the same decision payment
 * gateways made: keeping encrypted credentials for an account the academy has
 * said it no longer uses is a secret held for no reason.
 *
 * Sessions already scheduled through it keep their join links — the link is a
 * column, not a lookup — and cancelling one still works, because
 * `CancelLiveSession` writes the cancellation here first and swallows the
 * provider call. What stops working is RESCHEDULING them, which reaches the
 * provider and now has no account to reach it with. That is what the
 * confirmation says, from the same count the resource reports.
 */
final class DisconnectLiveProvider
{
    public function handle(LiveProvider $provider): void
    {
        LiveProviderAccount::query()->where('provider', $provider)->delete();
    }
}
