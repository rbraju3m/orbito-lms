<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Actions\SuspendUser;
use App\Domain\Identity\Models\User;
use App\Http\Requests\Identity\SuspendUserRequest;
use App\Http\Resources\Identity\UserResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class UserController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $users = User::query()
            ->with(['roleAssignments.role', 'instructorProfile'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = $request->string('q')->value();
                $query->where(function ($q) use ($term): void {
                    $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('role'), function ($query) use ($request): void {
                $query->whereHas('roleAssignments.role', fn ($q) => $q->where('key', $request->string('role')->value()));
            })
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::ok(UserResource::collection($users));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::ok(
            UserResource::make($user->load(['socialLinks', 'instructorProfile', 'roleAssignments.role']))
        );
    }

    public function suspend(SuspendUserRequest $request, User $user, SuspendUser $action): JsonResponse
    {
        Gate::authorize('suspend', $user);

        $action->handle($user, $request->boolean('suspended'));

        return ApiResponse::ok(UserResource::make($user->fresh()));
    }
}
