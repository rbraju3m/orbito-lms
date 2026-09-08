<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\PublishAnnouncement;
use App\Domain\Engagement\Models\Announcement;
use App\Http\Requests\Engagement\StoreAnnouncementRequest;
use App\Http\Resources\Engagement\AnnouncementResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AnnouncementController
{
    /**
     * A course's announcements.
     *
     * Drafts are included only for people who can publish them — filtered by
     * what the reader may see, not by a query parameter (Phase 8).
     */
    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('viewAny', [Announcement::class, $course]);

        $canManage = Gate::allows('manage', [Announcement::class, $course]);

        $announcements = Announcement::query()
            ->with('author')
            ->where('course_id', $course->id)
            ->unless($canManage, fn ($query) => $query->published())
            // Drafts have no published_at, so they sort first for staff — which
            // is right: the unfinished thing is the one needing attention.
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        // The same Gate the write endpoints use, so the studio shows a
        // compose box exactly when the server would accept one.
        return ApiResponse::ok(
            AnnouncementResource::collection($announcements)
                ->additional(['meta' => ['can_manage' => $canManage]]),
        );
    }

    /** Creates a DRAFT. Publishing is a separate, deliberate act. */
    public function store(StoreAnnouncementRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('manage', [Announcement::class, $course]);

        $announcement = Announcement::create([
            'course_id' => $course->id,
            'author_id' => $request->user()->id,
            'title' => $request->string('title')->value(),
            'body' => $request->string('body')->value(),
            'published_at' => null,
            'notify' => $request->has('notify') ? $request->boolean('notify') : true,
        ]);

        return ApiResponse::created(AnnouncementResource::make($announcement->load('author')));
    }

    public function update(StoreAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        Gate::authorize('manage', [Announcement::class, $announcement->loadMissing('course')->course]);

        $announcement->fill($request->only(['title', 'body']));

        if ($request->has('notify')) {
            $announcement->notify = $request->boolean('notify');
        }

        $announcement->save();

        return ApiResponse::ok(AnnouncementResource::make($announcement->fresh('author')));
    }

    public function publish(Announcement $announcement, PublishAnnouncement $action): JsonResponse
    {
        Gate::authorize('manage', [Announcement::class, $announcement->loadMissing('course')->course]);

        return ApiResponse::ok(
            AnnouncementResource::make($action->handle($announcement)->load('author')),
        );
    }

    public function unpublish(Announcement $announcement, PublishAnnouncement $action): JsonResponse
    {
        Gate::authorize('manage', [Announcement::class, $announcement->loadMissing('course')->course]);

        return ApiResponse::ok(
            AnnouncementResource::make($action->unpublish($announcement)->load('author')),
        );
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        Gate::authorize('manage', [Announcement::class, $announcement->loadMissing('course')->course]);

        $announcement->delete();

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
