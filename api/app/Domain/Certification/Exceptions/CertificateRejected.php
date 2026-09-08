<?php

declare(strict_types=1);

namespace App\Domain\Certification\Exceptions;

use App\Support\Exceptions\DomainException;

final class CertificateRejected extends DomainException
{
    public static function courseNotCompleted(): self
    {
        return new self('This course has not been completed yet.');
    }

    public static function notEnabled(): self
    {
        return new self('This course does not award a certificate.');
    }

    public static function alreadyRevoked(): self
    {
        return new self('This certificate has already been revoked.');
    }

    public static function noTemplate(): self
    {
        return new self('This academy has no active certificate template.');
    }

    public function errorCode(): string
    {
        return 'certificate_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
