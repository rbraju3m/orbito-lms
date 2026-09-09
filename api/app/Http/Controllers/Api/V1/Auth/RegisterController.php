<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\RegisterUser;
use App\Domain\Platform\Actions\ResolveSignupAcademy;
use App\Domain\Platform\Support\AuthenticatedAcademy;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Identity\AuthenticatedUserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class RegisterController
{
    public function __invoke(
        RegisterRequest $request,
        RegisterUser $action,
        ResolveSignupAcademy $signupAcademy,
        AuthenticatedAcademy $academy,
    ): JsonResponse {
        // Refuses before anything is created: no such academy, closed, or not
        // accepting sign-ups.
        $user = $action->handle(
            $request->toData(),
            $signupAcademy->handle($request->string('academy')->trim()->value()),
        );

        $token = null;

        // A device name means "token client"; otherwise this is the first-party
        // SPA and the session cookie is the credential.
        if ($request->filled('device_name')) {
            $token = $user->createToken($request->string('device_name')->value())->plainTextToken;
        } else {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        // Establish the caller BEFORE serialising: resources decide what to
        // reveal from $request->user(), and this response is for the new user
        // themselves — they must see their own email address.
        $request->setUserResolver(fn () => $user);

        // Opens their academy and loads the payload's relations from inside it.
        // Most of this payload is tenant data on a route the `tenant`
        // middleware cannot serve — see AuthenticatedAcademy.
        $payload = AuthenticatedUserResource::make($academy->prepare($user))->resolve($request);

        if ($token !== null) {
            $payload['token'] = $token;
        }

        return ApiResponse::created($payload);
    }
}
