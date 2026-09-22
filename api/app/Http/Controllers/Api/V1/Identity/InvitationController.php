<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Identity;

use App\Domain\Identity\Actions\ResendInvitation;
use App\Domain\Identity\Actions\RevokeInvitation;
use App\Domain\Identity\Actions\SendInvitation;
use App\Domain\Identity\Enums\InvitationStatus;
use App\Domain\Identity\Models\Invitation;
use App\Http\Requests\Identity\SendInvitationRequest;
use App\Http\Resources\Identity\InvitationResource;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** An academy's invitations (`invitation.manage`). See docs/INVITATIONS.md. */
final class InvitationController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Invitation::class);

        $status = InvitationStatus::tryFrom((string) $request->query('status', ''));
        $search = addcslashes(trim((string) $request->query('q', '')), '%_\\');

        $invitations = Invitation::query()
            ->when($status !== null, fn (Builder $query) => $query->withStatus($status))
            ->when($search !== '', fn (Builder $query) => $query->where('email', 'like', '%'.$search.'%'))
            ->orderByDesc('created_at')
            // Second precision; without a tiebreak a row can appear on two
            // pages or on none (§ Phase 8).
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page')));

        return ApiResponse::ok(InvitationResource::collection($invitations));
    }

    public function store(SendInvitationRequest $request, SendInvitation $action): JsonResponse
    {
        Gate::authorize('create', Invitation::class);

        $invitation = $action->handle($request->email(), $request->invitationRole(), $request->user());

        return ApiResponse::created(InvitationResource::make($invitation));
    }

    public function resend(Request $request, Invitation $invitation, ResendInvitation $action): JsonResponse
    {
        Gate::authorize('resend', $invitation);

        return ApiResponse::ok(InvitationResource::make($action->handle($invitation, $request->user())));
    }

    public function destroy(Request $request, Invitation $invitation, RevokeInvitation $action): JsonResponse
    {
        Gate::authorize('revoke', $invitation);

        return ApiResponse::ok(InvitationResource::make($action->handle($invitation, $request->user())));
    }
}
