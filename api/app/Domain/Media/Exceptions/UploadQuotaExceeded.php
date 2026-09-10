<?php

declare(strict_types=1);

namespace App\Domain\Media\Exceptions;

use App\Domain\Media\Support\ByteSize;
use App\Support\Exceptions\DomainException;

/**
 * 409, not 422: nothing is wrong with the file. It is the owner's OTHER
 * uploads that are in the way, and `meta` says by how much.
 */
final class UploadQuotaExceeded extends DomainException
{
    public static function over(int $usedBytes, int $limitBytes, int $fileBytes): self
    {
        $exception = new self(sprintf(
            'You have %s of uploads you have not handed in yet, and this file would take you past the %s limit. Hand in or remove some of them first.',
            ByteSize::human($usedBytes),
            ByteSize::human($limitBytes),
        ));

        $exception->meta = [
            'used_bytes' => $usedBytes,
            'limit_bytes' => $limitBytes,
            'file_bytes' => $fileBytes,
        ];

        return $exception;
    }

    public function errorCode(): string
    {
        return 'upload_quota_exceeded';
    }

    public function status(): int
    {
        return 409;
    }
}
