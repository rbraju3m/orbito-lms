<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

final class EmailAlreadyVerified extends DomainException
{
    public function __construct(string $message = 'This email address is already verified.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'email_already_verified';
    }

    public function status(): int
    {
        return 409;
    }
}
