<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Resources\Identity\AuthenticatedUserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing([
            'roleAssignments.role.permissions',
            'instructorProfile',
            'socialLinks',
            // Eager, not lazy: strict mode forbids the implicit load, and the
            // resource names the academy the caller is currently inside.
            'tenant',
        ]);

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return ApiResponse::ok(AuthenticatedUserResource::make($user)->resolve($request));
    }
}
