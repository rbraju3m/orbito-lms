<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/** The failed checks ride in `details[]`, so the studio renders the server's own rules. */
final class DownloadNotPublishable extends DomainException
{
    /** @param  list<array{field?: string, code: string, message: string}>  $failures */
    public function __construct(array $failures)
    {
        parent::__construct('This download is not ready to publish yet.');
        $this->details = $failures;
    }

    public function errorCode(): string
    {
        return 'download_not_publishable';
    }

    public function status(): int
    {
        return 422;
    }
}
