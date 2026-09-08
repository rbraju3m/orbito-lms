<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use App\Support\Exceptions\DomainException;

final class ReviewRejected extends DomainException
{
    public static function notEnrolled(): self
    {
        return new self('Only people enrolled in this course can review it.');
    }

    public static function notEnabled(): self
    {
        return new self('Reviews are turned off for this course.');
    }

    public static function alreadyReviewed(): self
    {
        return new self('You have already reviewed this course. Edit your review instead.');
    }

    public function errorCode(): string
    {
        return 'review_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
