<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

final class InstructorApplicationConflict extends DomainException
{
    public static function alreadyApplied(): self
    {
        return new self('Your instructor application is already under review.');
    }

    public static function alreadyApproved(): self
    {
        return new self('You are already an approved instructor.');
    }

    public static function blocked(): self
    {
        return new self('Your instructor account is blocked. Contact support.');
    }

    public static function notPending(): self
    {
        return new self('This application is not awaiting review.');
    }

    public function errorCode(): string
    {
        return 'instructor_application_conflict';
    }

    public function status(): int
    {
        return 409;
    }
}
