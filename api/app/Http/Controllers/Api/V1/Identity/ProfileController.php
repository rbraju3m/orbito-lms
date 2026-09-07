<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Identity;

use App\Domain\Identity\Actions\ChangePassword;
use App\Domain\Identity\Actions\UpdateProfile;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Identity\UpdateProfileRequest;
use App\Http\Resources\Identity\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProfileController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['socialLinks', 'instructorProfile']);

        return ApiResponse::ok(UserResource::make($user));
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $action): JsonResponse
    {
        $user = $action->handle(
            $request->user(),
            $request->profileAttributes(),
            $request->socialLinks(),
        );

        return ApiResponse::ok(UserResource::make($user));
    }

    public function changePassword(ChangePasswordRequest $request, ChangePassword $action): JsonResponse
    {
        $action->handle($request->user(), $request->string('password')->value());

        return ApiResponse::ok(['changed' => true]);
    }
}
