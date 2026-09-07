<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Domain\Assessment\Actions\ManageAssignment;
use App\Domain\Assessment\Models\Assignment;
use App\Domain\Curriculum\Models\CourseItem;
use App\Http\Requests\Assessment\UpdateAssignmentRequest;
use App\Http\Resources\Assessment\AssignmentResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Assignment authoring. Reached through the curriculum item that owns it. */
final class AssignmentBuilderController
{
    public function show(Request $request, CourseItem $item): JsonResponse
    {
        $assignment = $this->assignmentFor($item);
        Gate::authorize('manage-assignment', $item->course);

        return ApiResponse::ok(
            AssignmentResource::make($assignment->load('attachments'))->resolve($request)
        );
    }

    public function update(
        UpdateAssignmentRequest $request,
        CourseItem $item,
        ManageAssignment $action,
    ): JsonResponse {
        $assignment = $this->assignmentFor($item);
        Gate::authorize('manage-assignment', $item->course);

        $updated = $action->update(
            $assignment,
            $request->safe()->except('attachment_media_ids'),
            $request->attachmentIds(),
        );

        return ApiResponse::ok(AssignmentResource::make($updated)->resolve($request));
    }

    private function assignmentFor(CourseItem $item): Assignment
    {
        $item->loadMissing(['course', 'itemable']);

        if (! $item->itemable instanceof Assignment) {
            throw new NotFoundHttpException;
        }

        return $item->itemable;
    }
}
