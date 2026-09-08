<?php

declare(strict_types=1);

namespace App\Domain\Certification\Actions;

use App\Domain\Certification\Enums\CertificateStatus;
use App\Domain\Certification\Exceptions\CertificateRejected;
use App\Domain\Certification\Models\Certificate;

/**
 * Withdraws a certificate.
 *
 * The row is NEVER deleted. A revoked certificate that vanished would make
 * its verification URL 404, and a 404 is indistinguishable from a forgery —
 * the one thing the page must be able to tell somebody is "this was real, and
 * it has been withdrawn". Deleting would protect the forger.
 *
 * The PDF is left in place for the same reason: copies are already in the
 * world, and the authoritative answer is the verification page, not the file.
 */
final class RevokeCertificate
{
    public function handle(Certificate $certificate, ?string $reason = null): Certificate
    {
        if ($certificate->status === CertificateStatus::Revoked) {
            throw CertificateRejected::alreadyRevoked();
        }

        $certificate->forceFill([
            'status' => CertificateStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();

        return $certificate->refresh();
    }
}
