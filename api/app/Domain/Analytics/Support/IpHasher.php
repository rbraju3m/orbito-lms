<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

use Illuminate\Support\Facades\Config;

/**
 * Turns an IP address into something countable but not traceable.
 *
 * Keyed on the application key, so the digest is useless outside this
 * deployment and a leaked analytics table is not a list of who was where. It
 * is deterministic within one deployment, which is the whole point: two visits
 * from one address collapse to one visitor.
 *
 * This is a pseudonym, NOT anonymisation. The address space is small enough to
 * enumerate given the key, so the hash is treated as personal data — it is
 * pruned by the same retention command as the rest of the row.
 */
final class IpHasher
{
    public static function hash(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) Config::get('app.key'));
    }
}
