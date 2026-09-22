<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\AcceptInvitation;
use App\Domain\Platform\Actions\ResolveSignupAcademy;
use App\Domain\Platform\Support\AuthenticatedAcademy;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Resources\Identity\AuthenticatedUserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Registration by invitation: `RegisterController`'s answer, with the academy
 * resolved WITHOUT its signup mode — an invitation works in every mode — and
 * the account made by `AcceptInvitation`.
 */
final class AcceptInvitationController
{
    public function __invoke(
        AcceptInvitationRequest $request,
        AcceptInvitation $action,
        ResolveSignupAcademy $signupAcademy,
        AuthenticatedAcademy $academy,
    ): JsonResponse {
        $user = $action->handle(
            $signupAcademy->forInvitation($request->string('academy')->trim()->value()),
            $request->token(),
            $request->toData(),
        );

        $token = null;

        if ($request->filled('device_name')) {
            $token = $user->createToken($request->string('device_name')->value())->plainTextToken;
        } else {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        // See RegisterController: the caller must be established before the
        // payload is resolved, and the payload is tenant data.
        $request->setUserResolver(fn () => $user);

        $payload = AuthenticatedUserResource::make($academy->prepare($user))->resolve($request);

        if ($token !== null) {
            $payload['token'] = $token;
        }

        return ApiResponse::created($payload);
    }
}
