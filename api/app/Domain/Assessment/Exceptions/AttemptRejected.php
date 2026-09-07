<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

use App\Support\Exceptions\DomainException;

final class AttemptRejected extends DomainException
{
    public static function noAttemptsLeft(int $allowed): self
    {
        return new self("You have used all {$allowed} attempts at this quiz.");
    }

    public static function alreadyInProgress(): self
    {
        return new self('You already have an attempt in progress.');
    }

    public static function alreadySubmitted(): self
    {
        return new self('This attempt has already been submitted.');
    }

    public static function expired(): self
    {
        return new self('Time ran out on this attempt.');
    }

    public static function noQuestions(): self
    {
        return new self('This quiz has no questions yet.');
    }

    public function errorCode(): string
    {
        return 'attempt_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
