<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Carries the failed checks as `details[]`, so the checklist the author sees
 * is rendered from the rules the server enforces. Same contract as
 * `CourseNotPublishable`.
 */
final class BundleNotPublishable extends DomainException
{
    /** @param  list<array{field?: string, code: string, message: string}>  $failures */
    public function __construct(array $failures)
    {
        parent::__construct('This bundle is not ready to publish yet.');
        $this->details = $failures;
    }

    public function errorCode(): string
    {
        return 'bundle_not_publishable';
    }

    public function status(): int
    {
        return 422;
    }
}
