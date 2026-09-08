<?php

declare(strict_types=1);

namespace App\Domain\Certification\Enums;

enum CertificateStatus: string
{
    case Issued = 'issued';
    case Revoked = 'revoked';

    /**
     * Whether the public page should present this as a valid certificate.
     *
     * Expiry is deliberately NOT folded in here: a certificate that has
     * expired was still genuinely issued, and the verification page must say
     * "issued, then expired" rather than "no such certificate". Conflating
     * them would make an honest holder look like a forger.
     */
    public function isValid(): bool
    {
        return $this === self::Issued;
    }

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Revoked => 'Revoked',
        };
    }
}
