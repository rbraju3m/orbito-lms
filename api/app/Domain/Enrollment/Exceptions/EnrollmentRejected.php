<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Exceptions;

use App\Domain\Catalog\Models\Course;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Collection;

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

    /** A cancelled enrolment is already closed; suspending it means nothing. */
    public static function notSuspendable(): self
    {
        return new self('This enrolment has been cancelled and cannot be suspended.');
    }

    /**
     * Names the outstanding courses rather than only refusing: "you need
     * something first" with no way to find out what is a dead end.
     *
     * @param  Collection<int, Course>  $unmet
     */
    public static function prerequisitesUnmet(Collection $unmet): self
    {
        $exception = new self(
            $unmet->count() === 1
                ? sprintf('Complete “%s” before enrolling in this course.', $unmet->first()->title)
                : 'Complete this course’s prerequisites before enrolling.',
        );

        $exception->details = $unmet
            ->map(fn (Course $course): array => [
                'field' => 'prerequisites',
                'code' => 'prerequisite_unmet',
                'message' => $course->title,
            ])
            ->values()
            ->all();

        $exception->meta = [
            'prerequisites' => $unmet
                ->map(fn (Course $course): array => [
                    'id' => $course->uuid,
                    'slug' => $course->slug,
                    'title' => $course->title,
                ])
                ->values()
                ->all(),
        ];

        return $exception;
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
