<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Platform\Support\AuthenticatedAcademy;
use App\Http\Resources\Identity\AuthenticatedUserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request, AuthenticatedAcademy $academy): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        /*
         * Most of this payload is TENANT data, and this is the one route the
         * `tenant` middleware deliberately lets through with no academy open —
         * a platform operator who has entered none. Loading the relations
         * through AuthenticatedAcademy is what keeps that case a 200 saying
         * "no academy" instead of a 500 about a missing table.
         */
        return ApiResponse::ok(
            AuthenticatedUserResource::make($academy->forCurrentRequest($user))->resolve($request)
        );
    }
}
