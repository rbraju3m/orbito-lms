<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Identity;

use App\Domain\Identity\Actions\ApplyAsInstructor;
use App\Http\Requests\Identity\ApplyAsInstructorRequest;
use App\Http\Resources\Identity\InstructorProfileResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InstructorApplicationController
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->loadMissing('instructorProfile')->instructorProfile;

        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(InstructorProfileResource::make($profile));
    }

    public function store(ApplyAsInstructorRequest $request, ApplyAsInstructor $action): JsonResponse
    {
        $profile = $action->handle(
            $request->user(),
            $request->string('message')->value() ?: null,
        );

        return ApiResponse::created(InstructorProfileResource::make($profile));
    }
}
