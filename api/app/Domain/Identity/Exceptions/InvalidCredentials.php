<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Deliberately says nothing about whether the email exists. An attacker must
 * not be able to enumerate accounts by comparing failure messages.
 */
final class InvalidCredentials extends DomainException
{
    public function __construct(string $message = 'These credentials do not match our records.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }

    public function status(): int
    {
        return 422;
    }
}
