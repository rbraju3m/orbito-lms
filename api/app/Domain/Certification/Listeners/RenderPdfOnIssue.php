<?php

declare(strict_types=1);

namespace App\Domain\Certification\Listeners;

use App\Domain\Certification\Actions\RenderCertificatePdf;
use App\Domain\Certification\Events\CertificateIssued;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Renders the PDF after the certificate exists.
 *
 * A separate listener from the issue, not a step inside it, because the two
 * fail differently and must recover differently. Minting is a database write
 * that either happened or did not; rendering is a slow, memory-hungry
 * operation that can fail on a font, a corrupt background image or a timeout.
 *
 * If rendering fails, the certificate is still ISSUED and still verifies —
 * the document is a rendering of the fact, not the fact itself. The retry
 * picks it up, and `certificates(status, pdf_media_id)` is indexed so a sweep
 * can find the ones still missing a file.
 */
final class RenderPdfOnIssue implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly RenderCertificatePdf $render) {}

    public function handle(CertificateIssued $event): void
    {
        $this->render->handle($event->certificate);
    }
}
