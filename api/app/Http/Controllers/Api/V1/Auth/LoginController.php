<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\AuthenticateUser;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Identity\AuthenticatedUserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class LoginController
{
    public function __invoke(LoginRequest $request, AuthenticateUser $action): JsonResponse
    {
        $user = $action->handle(
            $request,
            mb_strtolower($request->string('email')->trim()->value()),
            $request->string('password')->value(),
        );

        $token = null;

        if ($request->filled('device_name')) {
            $token = $user->createToken($request->string('device_name')->value())->plainTextToken;
        } else {
            Auth::guard('web')->login($user, $request->boolean('remember'));
            // Rotate the session id on privilege change — the fixation defence.
            $request->session()->regenerate();
        }

        $request->setUserResolver(fn () => $user);

        $payload = AuthenticatedUserResource::make(
            $user->fresh(['roleAssignments.role.permissions', 'instructorProfile'])
        )->resolve($request);

        if ($token !== null) {
            $payload['token'] = $token;
        }

        return ApiResponse::ok($payload);
    }
}
