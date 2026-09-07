<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

final class AccountSuspended extends DomainException
{
    public function __construct(string $message = 'This account has been suspended.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'account_suspended';
    }

    public function status(): int
    {
        return 403;
    }
}
