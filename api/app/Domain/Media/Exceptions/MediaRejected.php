<?php

declare(strict_types=1);

namespace App\Domain\Media\Exceptions;

use App\Domain\Media\Support\ByteSize;
use App\Support\Exceptions\DomainException;

final class MediaRejected extends DomainException
{
    public static function mimeNotAllowed(string $mime, string $collection): self
    {
        return new self("Files of type {$mime} are not accepted for {$collection}.");
    }

    public static function tooLarge(int $bytes, int $max): self
    {
        return new self(sprintf(
            'That file is %s; the limit is %s.',
            ByteSize::human($bytes),
            ByteSize::human($max),
        ));
    }

    public function errorCode(): string
    {
        return 'media_rejected';
    }

    public function status(): int
    {
        return 422;
    }
}
