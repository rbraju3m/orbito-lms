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

        $account = $this->account($provider);

        if ($account === null) {
            throw LiveSessionRejected::providerNotConnected($provider->value);
        }

        return [$this->implementation($provider), $account->toProviderAccount()];
    }

    /**
     * Whether a session could be scheduled with this provider right now — the
     * same answer `for()` enforces, so the studio's picker cannot offer a
     * provider that would then refuse.
     */
    public function isConnected(LiveProvider $provider): bool
    {
        return ! $provider->needsAccount() || $this->account($provider) !== null;
    }

    /**
     * Every provider and whether it can be scheduled with right now — the
     * list every picker is built from, so a course's session form and a
     * webinar's cannot disagree about what this academy has connected.
     *
     * @return list<array{value: string, label: string, available: bool}>
     */
    public function options(): array
    {
        return array_map(fn (LiveProvider $provider): array => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'available' => $this->isConnected($provider),
        ], LiveProvider::cases());
    }

    private function account(LiveProvider $provider): ?LiveProviderAccount
    {
        return LiveProviderAccount::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();
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
