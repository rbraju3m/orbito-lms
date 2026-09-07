<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use App\Domain\Enrollment\Queries\AccessDecision;
use App\Support\Exceptions\DomainException;

/**
 * 423 Locked, not 403: the caller is authenticated and the content exists —
 * they simply do not have access to it yet. The frontend shows a "get access"
 * screen for this, not an error.
 */
final class ContentLocked extends DomainException
{
    public static function from(AccessDecision $decision): self
    {
        $exception = new self(match ($decision->reason) {
            'not_enrolled' => 'Enrol in this course to open this lesson.',
            'enrollment_expired' => 'Your access to this course has expired.',
            'enrollment_suspended' => 'Your access to this course is suspended.',
            'unauthenticated' => 'Sign in to open this lesson.',
            default => 'You do not have access to this content.',
        });

        $exception->details = [['code' => $decision->reason, 'message' => $exception->getMessage()]];

        return $exception;
    }

    public function errorCode(): string
    {
        return 'content_locked';
    }

    public function status(): int
    {
        return 423;
    }
}
