<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use App\Domain\Enrollment\Queries\AccessDecision;
use App\Support\Exceptions\DomainException;

/**
 * 423 Locked, not 403: the caller is authenticated and the content exists —
 * they simply do not have access to it yet. The frontend shows a "get access"
 * screen for this, not an error.
 *
 * The decision's `unlocksAt` and `meta` ride along in `details`, because a
 * locked screen that cannot say WHEN or WHAT FIRST is a dead end.
 */
final class ContentLocked extends DomainException
{
    public static function from(AccessDecision $decision): self
    {
        $exception = new self(match ($decision->reason) {
            'not_enrolled' => 'Enrol in this course to open this lesson.',
            'enrollment_expired' => 'Your access to this course has expired.',
            'enrollment_suspended' => 'Your access to this course is suspended.',
            'enrollment_cancelled' => 'Your enrolment in this course was cancelled.',
            'enrollment_not_started' => 'Your access to this course has not started yet.',
            'drip_locked' => self::dripMessage($decision),
            'unauthenticated' => 'Sign in to open this lesson.',
            default => 'You do not have access to this content.',
        });

        $exception->details = [['code' => $decision->reason, 'message' => $exception->getMessage()]];

        $exception->meta = array_filter([
            'unlocks_at' => $decision->unlocksAt?->toIso8601String(),
            ...$decision->meta,
        ], static fn (mixed $value): bool => $value !== null);

        return $exception;
    }

    /**
     * Drip locks are the one denial the learner can do something about, so the
     * message names the thing: an item to finish, or a date to wait for.
     */
    private static function dripMessage(AccessDecision $decision): string
    {
        $blocking = $decision->meta['blocked_by_title'] ?? null;

        if (is_string($blocking) && $blocking !== '') {
            return sprintf('Finish “%s” to unlock this lesson.', $blocking);
        }

        if ($decision->unlocksAt !== null) {
            return sprintf(
                'This lesson unlocks on %s.',
                $decision->unlocksAt->isoFormat('D MMMM YYYY'),
            );
        }

        return 'This lesson is not available yet.';
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
