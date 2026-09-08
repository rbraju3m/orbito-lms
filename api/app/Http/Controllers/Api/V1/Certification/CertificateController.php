<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Certification;

use App\Domain\Certification\Actions\RevokeCertificate;
use App\Domain\Certification\Models\Certificate;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Http\Requests\Certification\RevokeCertificateRequest;
use App\Http\Resources\Certification\CertificateResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CertificateController
{
    /**
     * The caller's own certificates — or everyone's, for staff holding
     * `certificate.view.any`.
     *
     * Filtered by what the reader may open rather than by a query parameter: a
     * row that 403s when clicked is a bug, not a permission check (Phase 8).
     */
    public function index(Request $request): JsonResponse
    {
        $certificates = Certificate::query()
            ->with('course')
            ->unless(
                $request->user()->hasPermission('certificate.view.any'),
                fn ($query) => $query->where('user_id', $request->user()->id),
            )
            // issued_at has second precision, so the id is the tiebreak that
            // stops a row appearing on two pages or on none.
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(CertificateResource::collection($certificates));
    }

    public function show(Certificate $certificate): JsonResponse
    {
        Gate::authorize('view', $certificate);

        return ApiResponse::ok(CertificateResource::make($certificate->load('course')));
    }

    /**
     * A short-lived signed URL for the PDF, not the bytes.
     *
     * Same shape as every other private file (ADR-09): the signature is the
     * credential, the URL expires, and the tenant travels inside the signed
     * payload because the download route has no authenticated user.
     */
    public function download(Certificate $certificate, MediaUrlGenerator $urls): JsonResponse
    {
        Gate::authorize('view', $certificate);

        $certificate->loadMissing('pdf');

        /*
         * 404, not 202: the render is queued and may still be in flight, but a
         * client asking for a file that does not exist is asking for something
         * that is not there. `has_pdf` on the resource is how a UI knows to
         * wait, so it should not be asking at all.
         */
        if ($certificate->pdf === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok([
            'url' => $urls->signed($certificate->pdf),
            'expires_at' => $urls->expiresAt(),
        ]);
    }

    public function revoke(
        RevokeCertificateRequest $request,
        Certificate $certificate,
        RevokeCertificate $action,
    ): JsonResponse {
        Gate::authorize('revoke', $certificate);

        $revoked = $action->handle(
            $certificate,
            $request->has('reason') ? (string) $request->string('reason') : null,
        );

        return ApiResponse::ok(CertificateResource::make($revoked->load('course')));
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
