<?php

declare(strict_types=1);

namespace App\Domain\Live\Data;

/**
 * One box on the "connect a provider" form.
 *
 * Declared on `LiveProvider` and both RENDERED and ENFORCED from there: the
 * screen builds its inputs from this list and `ConnectLiveProviderRequest`
 * refuses a key that is not in it. The alternative — a form that knows Zoom
 * needs four fields and a validator that knows separately — is two
 * definitions of one fact, and the one that drifts is the one nobody tests
 * (§ Patterns established in Phase 10, `PublishChecklist`).
 *
 * `isSecret` decides a password input on the screen; it is not what keeps a
 * value safe. Every credential is encrypted at rest and absent from every
 * resource, secret or not — an account id is no less dangerous for being
 * boring.
 */
final class CredentialField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $help,
        public readonly bool $isRequired = true,
        public readonly bool $isSecret = false,
    ) {}

    /** @return array{key: string, label: string, help: string, required: bool, secret: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'help' => $this->help,
            'required' => $this->isRequired,
            'secret' => $this->isSecret,
        ];
    }
}
