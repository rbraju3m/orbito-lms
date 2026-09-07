<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

final class PrerequisiteRejected extends DomainException
{
    public static function selfReference(): self
    {
        return new self('A course cannot be its own prerequisite.');
    }

    public static function cycle(): self
    {
        return new self('That would create a loop no learner could ever enter.');
    }

    public static function unknownCourse(): self
    {
        return new self('One of those courses does not exist.');
    }

    public static function tooMany(int $max): self
    {
        return new self("A course can have at most {$max} prerequisites.");
    }

    public function errorCode(): string
    {
        return 'prerequisite_rejected';
    }

    public function status(): int
    {
        return 422;
    }
}
