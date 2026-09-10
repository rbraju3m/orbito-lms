<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Exceptions;

use App\Support\Exceptions\DomainException;

final class WebhookTargetRefused extends DomainException
{
    public static function malformed(): self
    {
        return new self('That is not a URL we can send to.');
    }

    public static function notHttps(): self
    {
        return new self('Webhook URLs must use https.');
    }

    public static function credentialsInUrl(): self
    {
        return new self('Put credentials in your receiver, not in the URL — verify the signature instead.');
    }

    public static function unresolvable(string $host): self
    {
        return new self("{$host} does not resolve to any address.");
    }

    public static function internal(string $host): self
    {
        return new self("{$host} points at a private or internal address, which webhooks are never sent to.");
    }

    public function errorCode(): string
    {
        return 'webhook_target_refused';
    }

    public function status(): int
    {
        return 422;
    }
}
