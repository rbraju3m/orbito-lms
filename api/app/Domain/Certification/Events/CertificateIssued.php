<?php

declare(strict_types=1);

namespace App\Domain\Certification\Events;

use App\Domain\Certification\Models\Certificate;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A certificate exists. The PDF does not yet — rendering listens to this.
 *
 * Fired exactly once per certificate, because IssueCertificate is idempotent
 * and only the mint that WON the unique constraint dispatches.
 */
final class CertificateIssued
{
    use Dispatchable;

    public function __construct(public readonly Certificate $certificate) {}
}
