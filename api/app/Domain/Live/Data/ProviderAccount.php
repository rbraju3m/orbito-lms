<?php

declare(strict_types=1);

namespace App\Domain\Live\Data;

use RuntimeException;

/**
 * One academy's decrypted credentials, handed to a provider implementation.
 *
 * Deliberately not the model, for the reason in Phase 10: an implementation
 * holding the row could persist it or leak it through an exception trace.
 */
final class ProviderAccount
{
    /** @param  array<string, string>  $credentials */
    public function __construct(public readonly array $credentials = []) {}

    public function get(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            // A missing credential surfaces here rather than as a confusing
            // 401 from somebody else's API.
            throw new RuntimeException("Live provider credential [{$key}] is not configured.");
        }

        return $value;
    }
}
