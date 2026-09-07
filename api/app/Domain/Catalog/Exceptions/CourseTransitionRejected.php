<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Catalog\Enums\CourseStatus;
use App\Support\Exceptions\DomainException;

final class CourseTransitionRejected extends DomainException
{
    public static function illegal(CourseStatus $from, CourseStatus $to): self
    {
        return new self("A {$from->label()} course cannot become {$to->label()}.");
    }

    public function errorCode(): string
    {
        return 'course_transition_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
