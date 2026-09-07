<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

final class InvalidVerificationLink extends DomainException
{
    public function __construct(string $message = 'This verification link is invalid or has expired.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'invalid_verification_link';
    }

    public function status(): int
    {
        return 422;
    }
}
