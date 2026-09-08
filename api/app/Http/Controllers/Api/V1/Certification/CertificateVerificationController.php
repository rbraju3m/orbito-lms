<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Certification;

use App\Domain\Certification\Models\Certificate;
use App\Http\Resources\Certification\CertificateVerificationResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public verification page. No authentication, by design.
 *
 * Its whole purpose is being usable by a stranger — a hiring manager holding a
 * printed certificate has no account and never will. That makes it the second
 * route in the system with no user and no Laravel signature, so the academy
 * comes from the path (`tenant.path`) and the 32-character token is the
 * credential.
 *
 * A wrong token is a flat 404, identical to a wrong academy, and the response
 * says nothing about which was wrong. Rate-limited because the token is the
 * only thing standing between a stranger and a person's name, and an
 * unthrottled lookup endpoint is a guessing machine.
 *
 * It answers for REVOKED and EXPIRED certificates too, deliberately. A
 * withdrawn certificate that 404'd would be indistinguishable from a forgery,
 * which protects the forger — the page has to be able to say "this was real,
 * and it was withdrawn".
 */
final class CertificateVerificationController
{
    public function __invoke(string $tenant, string $token): JsonResponse
    {
        /*
         * Length-checked before the query. The column is CHAR(32); a 200KB
         * token is a probe, not a mistake, and there is no reason to hand it
         * to the database.
         */
        if (strlen($token) !== 32) {
            throw new NotFoundHttpException;
        }

        $certificate = Certificate::query()
            ->where('verification_token', $token)
            ->first();

        if ($certificate === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(CertificateVerificationResource::make($certificate));
    }
}
