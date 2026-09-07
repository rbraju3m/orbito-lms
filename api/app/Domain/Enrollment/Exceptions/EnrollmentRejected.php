<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use App\Support\Exceptions\DomainException;

final class EnrollmentRejected extends DomainException
{
    public static function alreadyEnrolled(): self
    {
        return new self('You are already enrolled in this course.');
    }

    public static function notAvailable(): self
    {
        return new self('This course is not open for enrolment.');
    }

    public static function requiresPayment(): self
    {
        return new self('This course must be purchased before you can enrol.');
    }

    public static function courseFull(): self
    {
        return new self('This course has reached its student limit.');
    }

    public function errorCode(): string
    {
        return 'enrollment_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
