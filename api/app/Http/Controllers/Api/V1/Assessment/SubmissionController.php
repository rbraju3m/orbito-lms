<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Domain\Assessment\Actions\SubmitAssignment;
use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Support\SubmissionRules;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Exceptions\ContentLocked;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Queries\AccessDecision;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Http\Requests\Assessment\StoreSubmissionRequest;
use App\Http\Resources\Assessment\AssignmentResource;
use App\Http\Resources\Assessment\SubmissionResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The learner's side of an assignment: what to do, what they have handed in,
 * and handing in the next one.
 */
final class SubmissionController
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly SubmitAssignment $submit,
    ) {}

    /** The brief, this learner's history, and whether they may submit again. */
    public function show(Request $request, CourseItem $item): JsonResponse
    {
        $assignment = $this->assignmentFor($item);
        $enrollment = $this->enrollmentFor($request, $item);

        $history = $this->submit->historyFor($assignment, $enrollment);

        return ApiResponse::ok([
            'assignment' => AssignmentResource::make($assignment->load('attachments'))->resolve($request),
            'submissions' => SubmissionResource::collection($history->reverse()->values())->resolve($request),
            // The same object the submit Action enforces, so the form and the
            // server cannot disagree about whether the button should work.
            'rules' => (new SubmissionRules($assignment, $history))->toArray(),
        ]);
    }

    public function store(StoreSubmissionRequest $request, CourseItem $item): JsonResponse
    {
        $assignment = $this->assignmentFor($item);
        $enrollment = $this->enrollmentFor($request, $item);

        $submission = $this->submit->handle(
            $item,
            $assignment,
            $enrollment,
            $request->input('body'),
            $request->files(),
        );

        return ApiResponse::created(
            SubmissionResource::make($submission->load('files.media'))->resolve($request)
        );
    }

    private function assignmentFor(CourseItem $item): Assignment
    {
        $item->loadMissing(['course', 'itemable']);

        if (! $item->itemable instanceof Assignment || ! $item->is_published) {
            throw new NotFoundHttpException;
        }

        return $item->itemable;
    }

    private function enrollmentFor(Request $request, CourseItem $item): Enrollment
    {
        $decision = $this->access->forItem($request->user(), $item);

        if (! $decision->granted) {
            throw ContentLocked::from($decision);
        }

        $enrollment = $decision->enrollment
            ?? ($request->user() !== null
                ? $this->access->enrollmentFor($request->user(), $item->course)
                : null);

        // Course staff can read an assignment but have no enrollment to hand
        // work in against, and inventing one would corrupt their own students'
        // statistics.
        if ($enrollment === null || ! $enrollment->isActive()) {
            throw ContentLocked::from(AccessDecision::deny('not_enrolled'));
        }

        return $enrollment;
    }
}
