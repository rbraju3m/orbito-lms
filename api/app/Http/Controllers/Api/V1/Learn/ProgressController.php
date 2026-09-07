<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Exceptions\ContentLocked;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Queries\AccessDecision;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Domain\Progress\Actions\CompleteCourse;
use App\Domain\Progress\Actions\ResetCourseProgress;
use App\Domain\Progress\Actions\TrackItemProgress;
use App\Http\Resources\Progress\CourseProgressResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProgressController
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly TrackItemProgress $track,
    ) {}

    public function complete(Request $request, CourseItem $item): JsonResponse
    {
        $enrollment = $this->enrollmentForItem($request, $item);

        $this->track->complete($enrollment, $item);

        return ApiResponse::ok(
            CourseProgressResource::make($enrollment->fresh('progress')->progress)->toArray($request)
        );
    }

    public function uncomplete(Request $request, CourseItem $item): JsonResponse
    {
        $enrollment = $this->enrollmentForItem($request, $item);

        $this->track->uncomplete($enrollment, $item);

        return ApiResponse::ok(
            CourseProgressResource::make($enrollment->fresh('progress')->progress)->toArray($request)
        );
    }

    /**
     * Video heartbeat. Rate-limited hard: the client sends this every 15s per
     * item, and it must never be able to cost more than that.
     */
    public function watch(Request $request, CourseItem $item): JsonResponse
    {
        $validated = $request->validate([
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
        ]);

        $enrollment = $this->enrollmentForItem($request, $item);
        $item->loadMissing('course.setting');

        $progress = $this->track->recordWatch(
            $enrollment,
            $item,
            (int) $validated['position_seconds'],
            (int) ($item->course->setting->video_completion_threshold ?? 90),
        );

        return ApiResponse::ok([
            'status' => $progress->status->value,
            'watch_position_seconds' => $progress->watch_position_seconds,
        ]);
    }

    public function completeCourse(Request $request, Course $course, CompleteCourse $action): JsonResponse
    {
        $enrollment = $this->enrollmentForCourse($request, $course);

        return ApiResponse::ok(
            CourseProgressResource::make($action->handle($enrollment))->toArray($request)
        );
    }

    public function reset(Request $request, Course $course, ResetCourseProgress $action): JsonResponse
    {
        $enrollment = $this->enrollmentForCourse($request, $course);
        $course->loadMissing('setting');

        if ($course->setting?->reset_progress_allowed === false) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(
            CourseProgressResource::make($action->handle($enrollment))->toArray($request)
        );
    }

    /**
     * Progress belongs to an enrollment. Course staff can read the player but
     * have nothing to record against, so this refuses rather than inventing one.
     */
    private function enrollmentForItem(Request $request, CourseItem $item): Enrollment
    {
        $item->loadMissing('course');

        return $this->enrollmentForCourse($request, $item->course);
    }

    private function enrollmentForCourse(Request $request, Course $course): Enrollment
    {
        $decision = $this->access->for($request->user(), $course);

        if (! $decision->granted) {
            throw ContentLocked::from($decision);
        }

        $enrollment = $decision->enrollment
            ?? $this->access->enrollmentFor($request->user(), $course);

        if ($enrollment === null || ! $enrollment->isActive()) {
            throw ContentLocked::from(
                AccessDecision::deny('not_enrolled')
            );
        }

        return $enrollment;
    }
}
