<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Engagement\Actions\AcceptDiscussionAnswer;
use App\Domain\Engagement\Actions\PostDiscussion;
use App\Domain\Engagement\Actions\ReplyToDiscussion;
use App\Domain\Engagement\Enums\DiscussionStatus;
use App\Domain\Engagement\Events\DiscussionReplied;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Http\Requests\Engagement\StoreDiscussionReplyRequest;
use App\Http\Requests\Engagement\StoreDiscussionRequest;
use App\Http\Resources\Engagement\DiscussionResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DiscussionController
{
    /**
     * A course's threads, optionally narrowed to one lesson.
     *
     * Pinned first, then most recently active — the index on
     * (course_id, status, is_pinned, last_reply_at) exists for exactly this
     * ordering.
     */
    public function index(Request $request, Course $course, CourseAccess $access): JsonResponse
    {
        Gate::authorize('viewAny', [Discussion::class, $course]);

        $moderator = $access->for($request->user(), $course)->isStaff()
            && $request->user()->hasPermission('discussion.moderate');

        $discussions = Discussion::query()
            ->with(['user', 'item'])
            ->where('course_id', $course->id)
            // Hidden threads are for moderators only — including from their
            // own authors, which is what hiding means.
            ->unless($moderator, fn ($query) => $query->visible())
            ->when(
                $request->filled('item_id'),
                fn ($query) => $query->whereHas(
                    'item',
                    fn ($q) => $q->where('uuid', $request->string('item_id')),
                ),
            )
            ->when($request->string('status')->value() !== '', fn ($query) => $query->where(
                'status',
                $request->string('status')->value(),
            ))
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_reply_at')
            // last_reply_at is null on a thread with no replies, and second-
            // precision besides, so the id is the tiebreak.
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(DiscussionResource::collection($discussions));
    }

    public function show(Discussion $discussion): JsonResponse
    {
        Gate::authorize('view', $discussion);

        return ApiResponse::ok(DiscussionResource::make(
            $discussion->load(['user', 'item', 'replies' => fn ($query) => $query
                ->counted()
                ->with('user', 'parent')
                ->orderBy('created_at')
                ->orderBy('id')]),
        ));
    }

    public function store(StoreDiscussionRequest $request, Course $course, PostDiscussion $action): JsonResponse
    {
        Gate::authorize('create', [Discussion::class, $course]);

        $item = $request->filled('item_id')
            ? CourseItem::where('uuid', $request->string('item_id'))->first()
            : null;

        $discussion = $action->handle(
            $request->user(),
            $course,
            $request->string('title')->value(),
            $request->string('body')->value(),
            $request->type(),
            $item,
        );

        return ApiResponse::created(DiscussionResource::make($discussion->load('user', 'item')));
    }

    public function reply(
        StoreDiscussionReplyRequest $request,
        Discussion $discussion,
        ReplyToDiscussion $action,
        CourseAccess $access,
    ): JsonResponse {
        Gate::authorize('reply', $discussion);

        $parent = $request->filled('parent_id')
            ? DiscussionReply::where('uuid', $request->string('parent_id'))->first()
            : null;

        /*
         * Decided by the SERVER, never sent by the client — a badge saying
         * "instructor" that a learner could set would be worthless.
         */
        $isInstructor = $access->for($request->user(), $discussion->loadMissing('course')->course)
            ->isStaff();

        $action->handle(
            $request->user(),
            $discussion,
            $request->string('body')->value(),
            $parent,
            $isInstructor,
        );

        return ApiResponse::created(DiscussionResource::make(
            $discussion->fresh(['user', 'item', 'replies' => fn ($query) => $query
                ->counted()->with('user', 'parent')->orderBy('created_at')->orderBy('id')]),
        ));
    }

    /** Accepting, or un-accepting when `reply_id` is absent. */
    public function accept(
        Request $request,
        Discussion $discussion,
        AcceptDiscussionAnswer $action,
    ): JsonResponse {
        Gate::authorize('accept', $discussion);

        $reply = $request->filled('reply_id')
            ? DiscussionReply::where('uuid', $request->string('reply_id'))->firstOrFail()
            : null;

        return ApiResponse::ok(DiscussionResource::make(
            $action->handle($discussion, $reply)->load('user', 'item'),
        ));
    }

    /** Hiding, unhiding and pinning — one endpoint, one capability. */
    public function moderate(Request $request, Discussion $discussion): JsonResponse
    {
        Gate::authorize('moderate', $discussion);

        $validated = $request->validate([
            'status' => ['sometimes', 'in:open,answered,resolved,hidden'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['status'])) {
            $discussion->status = DiscussionStatus::from($validated['status']);
        }

        if (isset($validated['is_pinned'])) {
            $discussion->is_pinned = (bool) $validated['is_pinned'];
        }

        $discussion->save();

        return ApiResponse::ok(DiscussionResource::make($discussion->fresh(['user', 'item'])));
    }

    public function destroyReply(DiscussionReply $reply): JsonResponse
    {
        Gate::authorize('delete-discussion-reply', $reply);

        $discussionId = $reply->discussion_id;
        $reply->delete();

        // The counters move, so the same event every other path fires.
        DiscussionReplied::dispatch($discussionId);

        return ApiResponse::noContent();
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
