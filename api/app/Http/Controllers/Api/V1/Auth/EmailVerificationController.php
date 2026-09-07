<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\VerifyEmail;
use App\Domain\Identity\Exceptions\EmailAlreadyVerified;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\Identity\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController
{
    /** The SPA replays the signed parameters from the emailed link. */
    public function verify(VerifyEmailRequest $request, VerifyEmail $action): JsonResponse
    {
        $user = $action->handle(
            $request->integer('id'),
            $request->string('hash')->value(),
        );

        return ApiResponse::ok([
            'verified' => true,
            'user' => UserResource::make($user)->toArray($request),
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            throw new EmailAlreadyVerified;
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::accepted(['sent' => true]);
    }
}
