<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

use App\Support\Exceptions\DomainException;

final class SubmissionRejected extends DomainException
{
    public static function noAttemptsLeft(int $allowed): self
    {
        return new self("You have used all {$allowed} attempts at this assignment.");
    }

    public static function pastDue(): self
    {
        return new self('The deadline for this assignment has passed.');
    }

    public static function alreadyGraded(): self
    {
        return new self('This submission has already been graded.');
    }

    public static function empty(): self
    {
        return new self('Write something or attach a file before handing this in.');
    }

    public static function textNotAllowed(): self
    {
        return new self('This assignment does not take a written answer.');
    }

    public static function filesNotAllowed(): self
    {
        return new self('This assignment does not take file uploads.');
    }

    public function errorCode(): string
    {
        return 'submission_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
