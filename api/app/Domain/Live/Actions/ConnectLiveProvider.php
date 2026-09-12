<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\LiveProviderAccount;

/**
 * Stores one academy's credentials for one meeting provider.
 *
 * The same shape as `ConnectPaymentGateway` (ADR-13), and for the same
 * reason: the credentials belong to the academy, so they live in its schema
 * and the platform holds none of its own. Until this runs,
 * `LiveProviderFactory` refuses and the studio's picker offers only "paste a
 * link".
 *
 * Credentials are WRITE-ONLY from the API's point of view. They are encrypted
 * by the model's casts, returned by no resource, and a partial update MERGES
 * rather than replaces: an admin fixing a mistyped account id must not have
 * to re-enter a client secret Zoom will only ever show them once.
 *
 * Completeness is checked against the merged result rather than the request,
 * because "what is stored now" is the only version that has to be schedulable
 * with — and it is checked at connect time rather than at a class's start
 * time, which is the difference between an admin fixing a typo and forty
 * people waiting.
 */
final class ConnectLiveProvider
{
    /** @param  array<string, string>|null  $credentials */
    public function handle(
        LiveProvider $provider,
        ?array $credentials = null,
        ?bool $isActive = null,
    ): LiveProviderAccount {
        if (! $provider->needsAccount()) {
            throw LiveSessionRejected::providerNeedsNoAccount($provider->value);
        }

        $account = LiveProviderAccount::firstOrNew(['provider' => $provider]);

        if ($credentials !== null) {
            // Blank means "keep what is stored", which is the only thing an
            // empty box CAN mean on a form that is never allowed to show the
            // current value.
            $account->credentials = [
                ...($account->credentials ?? []),
                ...array_filter($credentials, static fn (string $value): bool => $value !== ''),
            ];
        }

        $missing = $this->missing($provider, $account->credentials ?? []);

        if ($missing !== []) {
            throw LiveSessionRejected::credentialsIncomplete($provider->value, $missing);
        }

        if ($isActive !== null) {
            $account->is_active = $isActive;
        }

        $account->save();

        return $account->refresh();
    }

    /**
     * The required keys this provider still has nothing for.
     *
     * Read from `LiveProvider::credentialFields()` — the same declaration the
     * connect screen renders and the form request validates against, so a
     * provider cannot be saved half-connected by a form that forgot a box.
     *
     * @param  array<string, string>  $credentials
     * @return list<string>
     */
    private function missing(LiveProvider $provider, array $credentials): array
    {
        $missing = [];

        foreach ($provider->credentialFields() as $field) {
            if ($field->isRequired && ($credentials[$field->key] ?? '') === '') {
                $missing[] = $field->key;
            }
        }

        return $missing;
    }
}
