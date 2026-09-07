<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Carries the failed checks as `details[]`, so the publish checklist the
 * instructor sees is rendered from the server's rules and cannot drift from them.
 */
final class CourseNotPublishable extends DomainException
{
    /** @param  list<array{field?: string, code: string, message: string}>  $failures */
    public function __construct(array $failures)
    {
        parent::__construct('This course is not ready to publish yet.');
        $this->details = $failures;
    }

    public function errorCode(): string
    {
        return 'course_not_publishable';
    }

    public function status(): int
    {
        return 422;
    }
}
