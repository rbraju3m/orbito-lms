<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Support;

/**
 * How a receiver knows a delivery came from us, and came recently.
 *
 *   Orbito-Signature: t=1789000000,v1=<hex HMAC-SHA256 of "t.body">
 *
 * The timestamp is inside the signed string, so a captured delivery cannot be
 * replayed later with a fresh `t` — a receiver rejects anything older than a
 * few minutes. `v1` names the scheme so a second one can sit beside it during
 * a migration. docs/WEBHOOKS.md shows a receiver verifying it.
 */
final class WebhookSigner
{
    public const HEADER = 'Orbito-Signature';

    /** 48 hex characters of randomness behind a prefix a human recognises. */
    public static function newSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }

    public function header(string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
