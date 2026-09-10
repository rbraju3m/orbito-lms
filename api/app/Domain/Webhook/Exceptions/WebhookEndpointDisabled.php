<?php

declare(strict_types=1);

namespace App\Domain\Webhook\Exceptions;

use App\Support\Exceptions\DomainException;

final class WebhookEndpointDisabled extends DomainException
{
    public static function make(): self
    {
        return new self('This endpoint is switched off. Switch it on before sending to it.');
    }

    public function errorCode(): string
    {
        return 'webhook_endpoint_disabled';
    }

    public function status(): int
    {
        return 409;
    }
}
