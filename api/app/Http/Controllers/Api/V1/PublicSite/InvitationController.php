<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Identity\Actions\AcceptInvitation;
use App\Http\Requests\Identity\InvitationTokenRequest;
use App\Http\Resources\Identity\InvitationPreviewResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * What an invitation link is for, shown to whoever holds it before they choose
 * a password (docs/INVITATIONS.md §3).
 *
 * On the public surface because the reader has no account yet, and it emits
 * nothing to a stranger WITHOUT the token: the token, not the namespace, is
 * the credential — the guest-place pages' argument.
 */
final class InvitationController
{
    public function show(InvitationTokenRequest $request, string $academy, AcceptInvitation $action): JsonResponse
    {
        return ApiResponse::ok(InvitationPreviewResource::make($action->usable($request->token())));
    }
}
