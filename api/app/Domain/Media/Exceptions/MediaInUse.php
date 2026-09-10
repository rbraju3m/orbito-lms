<?php

declare(strict_types=1);

namespace App\Domain\Media\Exceptions;

use App\Support\Exceptions\DomainException;

final class MediaInUse extends DomainException
{
    public static function byDownload(string $title): self
    {
        return new self(sprintf(
            'This file is what buyers of "%s" receive, so it cannot be deleted. Replace the file on that download first, or archive it.',
            $title,
        ));
    }

    public static function bySubmission(): self
    {
        return new self('This file has been handed in with an assignment, so it cannot be deleted.');
    }

    public function errorCode(): string
    {
        return 'media_in_use';
    }

    public function status(): int
    {
        return 409;
    }
}
