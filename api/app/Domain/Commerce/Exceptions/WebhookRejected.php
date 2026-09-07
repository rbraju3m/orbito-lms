<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A webhook we will not act on.
 *
 * Always 400, and always vague. A gateway retries on any non-2xx, and the
 * caller here is either the provider (which needs no detail) or somebody
 * probing the endpoint (which must be told nothing). The specifics go to
 * `payment_events` and the log, where an operator can see them.
 */
final class WebhookRejected extends DomainException
{
    public static function badSignature(): self
    {
        return new self('Signature verification failed.');
    }

    public static function malformed(): self
    {
        return new self('Malformed webhook payload.');
    }

    public static function unknownGateway(): self
    {
        return new self('Unknown gateway.');
    }

    public static function notConfigured(): self
    {
        return new self('This gateway is not connected.');
    }

    public function errorCode(): string
    {
        return 'webhook_rejected';
    }

    public function status(): int
    {
        return 400;
    }
}
