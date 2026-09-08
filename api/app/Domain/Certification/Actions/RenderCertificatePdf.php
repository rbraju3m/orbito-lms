<?php

declare(strict_types=1);

namespace App\Domain\Certification\Actions;

use App\Domain\Certification\Models\Certificate;
use App\Domain\Certification\Support\CertificateHtml;
use App\Domain\Media\Actions\StoreGeneratedMedia;
use App\Domain\Media\Enums\MediaCollection;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a certificate to PDF and attaches it.
 *
 * Idempotent by re-rendering rather than by refusing: calling this twice
 * replaces the file, which is what an operator re-running a failed render
 * wants. The old Media row is deliberately left behind rather than deleted —
 * a signed URL somebody is holding should not 404 mid-download because a
 * re-render happened, and orphaned certificate PDFs are small and rare.
 *
 * dompdf was chosen over headless Chrome because this host cannot reliably run
 * Chrome (the same constraint that blocks Playwright here). The cost is real:
 * no flex, no grid, so `CertificateHtml` lays out with absolute positioning
 * and tables.
 */
final class RenderCertificatePdf
{
    public function __construct(
        private readonly StoreGeneratedMedia $store,
        private readonly CertificateHtml $html,
    ) {}

    public function handle(Certificate $certificate): Certificate
    {
        $certificate->loadMissing(['template', 'course']);

        $options = new Options;
        // The HTML is ours, but a template's background image is not
        // necessarily — and remote fetching inside a PDF renderer is an SSRF
        // surface. Everything is embedded as a data URI instead.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper(
            $certificate->template?->orientation->pageSize() ?? 'a4',
            $certificate->template?->orientation->value ?? 'landscape',
        );
        $dompdf->loadHtml($this->html->for($certificate));
        $dompdf->render();

        $media = $this->store->handle(
            ownerId: $certificate->user_id,
            contents: (string) $dompdf->output(),
            collection: MediaCollection::Certificate,
            mime: 'application/pdf',
            extension: 'pdf',
            name: $certificate->number.'.pdf',
        );

        $certificate->forceFill(['pdf_media_id' => $media->id])->save();

        return $certificate->refresh();
    }
}
