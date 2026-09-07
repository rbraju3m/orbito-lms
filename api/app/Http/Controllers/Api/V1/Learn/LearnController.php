<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\Lesson;
use App\Domain\Enrollment\Exceptions\ContentLocked;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Domain\Progress\Actions\TrackItemProgress;
use App\Domain\Progress\Queries\PlayerQuery;
use App\Http\Resources\Progress\CourseProgressResource;
use App\Http\Resources\Progress\LearnerSectionResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LearnController
{
    public function __construct(private readonly CourseAccess $access) {}

    /** Everything the player needs to render, in one request. */
    public function bootstrap(Request $request, Course $course, PlayerQuery $query): JsonResponse
    {
        $user = $request->user();
        $decision = $this->access->for($user, $course);

        if (! $decision->granted && ! $course->status->isLive()) {
            // Do not confirm an unpublished course exists to someone who
            // cannot see it.
            throw new NotFoundHttpException;
        }

        $enrollment = $decision->enrollment
            ?? ($user !== null ? $this->access->enrollmentFor($user, $course) : null);

        $enrollment?->loadMissing('progress.lastItem');

        return ApiResponse::ok([
            'course' => [
                'id' => $course->uuid,
                'slug' => $course->slug,
                'title' => $course->title,
                'completion_mode' => $course->completion_mode->value,
                'item_count' => $course->item_count,
                'total_duration_seconds' => $course->total_duration_seconds,
            ],
            'access' => [
                'granted' => $decision->granted,
                'reason' => $decision->reason,
                'source' => $decision->source,
                'is_staff' => $decision->isStaff(),
                'expires_at' => $decision->expiresAt?->toIso8601String(),
            ],
            'progress' => $enrollment?->progress !== null
                ? CourseProgressResource::make($enrollment->progress)->toArray($request)
                : null,
            'curriculum' => LearnerSectionResource::collection(
                $query->curriculumWithProgress($course, $enrollment),
            )->toArray($request),
        ]);
    }

    /**
     * The content of one item. This is the gate that matters: everything a
     * learner has not paid or enrolled for is refused here.
     */
    public function item(
        Request $request,
        CourseItem $item,
        TrackItemProgress $track,
        MediaUrlGenerator $urls,
    ): JsonResponse {
        $item->loadMissing(['course', 'itemable']);

        if (! $item->is_published) {
            throw new NotFoundHttpException;
        }

        $decision = $this->access->forItem($request->user(), $item);

        if (! $decision->granted) {
            throw ContentLocked::from($decision);
        }

        // Viewing is what creates the progress row — lazily, on first open.
        if ($decision->enrollment !== null) {
            $track->view($decision->enrollment, $item);
        }

        return ApiResponse::ok([
            'id' => $item->uuid,
            'type' => $item->type->value,
            'title' => $item->title,
            'duration_seconds' => $item->duration_seconds,
            'is_preview' => $item->is_preview,
            'content' => $this->content($item, $urls),
            'previous_id' => $item->previous()?->uuid,
            'next_id' => $item->next()?->uuid,
        ]);
    }

    /** @return array<string, mixed> */
    private function content(CourseItem $item, MediaUrlGenerator $urls): array
    {
        $itemable = $item->itemable;

        if ($itemable instanceof Lesson) {
            return [
                'body' => $itemable->content,
                'format' => $itemable->content_format->value,
                'video_provider' => $itemable->video_provider->value,
                'video_url' => $itemable->video_url,
                // A private uploaded video is only ever a short-lived signed
                // URL, minted after access was granted above (ADR-09).
                'video_signed_url' => $itemable->video !== null ? $urls->for($itemable->video) : null,
                'video_duration_seconds' => $itemable->video_duration_seconds,
            ];
        }

        if ($itemable instanceof \App\Domain\Curriculum\Models\Resource) {
            return [
                'description' => $itemable->description,
                'download_allowed' => $itemable->download_allowed,
                'url' => $itemable->media !== null
                    ? $urls->for($itemable->media)
                    : $itemable->external_url,
            ];
        }

        return [];
    }
}
