<?php

declare(strict_types=1);

namespace App\Domain\Live\Providers;

use App\Domain\Live\Data\ProviderAccount;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\LiveProviderAccount;

/**
 * Resolves an implementation AND the academy's account for it, together.
 *
 * Together on purpose, the same as PaymentGatewayFactory: an implementation is
 * useless without credentials, and separating them invites a call site that
 * builds one and forgets the other.
 */
final class LiveProviderFactory
{
    /** @return array{0: LiveSessionProvider, 1: ProviderAccount} */
    public function for(LiveProvider $provider): array
    {
        if (! $provider->needsAccount()) {
            // Manual needs nothing, which is why a fresh academy can schedule
            // a live session on day one.
            return [new ManualProvider, new ProviderAccount];
        }

        $account = LiveProviderAccount::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw LiveSessionRejected::providerNotConnected($provider->value);
        }

        return [$this->implementation($provider), $account->toProviderAccount()];
    }

    private function implementation(LiveProvider $provider): LiveSessionProvider
    {
        return match ($provider) {
            LiveProvider::Manual => new ManualProvider,
            LiveProvider::Zoom => new ZoomProvider,
            LiveProvider::GoogleMeet => new GoogleMeetProvider,
        };
    }
}
