<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Support\Http\ApiResponse;
use App\Support\Http\PublicFormToken;
use Illuminate\Http\JsonResponse;

/**
 * A token for a public form that asks no consent question — the guest webinar
 * registration. The lead form gets its token from `GET lead-form`, beside the
 * consent wording it has to show. `no-store`: a token served from a cache is a
 * token somebody else was issued.
 */
final class FormTokenController
{
    public function __invoke(string $academy, PublicFormToken $tokens): JsonResponse
    {
        return ApiResponse::ok(['token' => $tokens->mint($academy, now())])
            ->header('Cache-Control', 'no-store');
    }
}
