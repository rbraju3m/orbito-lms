<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Actions\ReviewInstructorApplication;
use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Models\InstructorProfile;
use App\Http\Requests\Identity\ReviewInstructorRequest;
use App\Http\Resources\Identity\InstructorProfileResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class InstructorController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', InstructorProfile::class);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $profiles = InstructorProfile::query()
            ->with('user')
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->value()),
            )
            ->orderByRaw('FIELD(status, ?, ?, ?, ?)', [
                InstructorStatus::Pending->value,
                InstructorStatus::Approved->value,
                InstructorStatus::Rejected->value,
                InstructorStatus::Blocked->value,
            ])
            ->latest('applied_at')
            ->paginate($perPage);

        return ApiResponse::ok(InstructorProfileResource::collection($profiles));
    }

    public function review(
        ReviewInstructorRequest $request,
        InstructorProfile $instructorProfile,
        ReviewInstructorApplication $action,
    ): JsonResponse {
        $decision = $request->decision();

        Gate::authorize(
            $decision === InstructorStatus::Blocked ? 'block' : 'review',
            InstructorProfile::class,
        );

        $profile = $action->handle(
            $instructorProfile,
            $decision,
            $request->user(),
            $request->string('note')->value() ?: null,
        );

        return ApiResponse::ok(InstructorProfileResource::make($profile->load('user')));
    }
}
