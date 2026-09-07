<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Enrollment\Exceptions\ContentLocked;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Domain\Progress\Models\LessonNote;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NoteController
{
    public function __construct(private readonly CourseAccess $access) {}

    public function index(Request $request, CourseItem $item): JsonResponse
    {
        $this->assertAccess($request, $item);

        $notes = LessonNote::query()
            ->where('user_id', $request->user()->id)
            ->where('course_item_id', $item->id)
            ->latest('id')
            ->get(['id', 'body', 'video_timestamp_seconds', 'created_at']);

        return ApiResponse::ok($notes);
    }

    public function store(Request $request, CourseItem $item): JsonResponse
    {
        $this->assertAccess($request, $item);
        $item->loadMissing('course');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'video_timestamp_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $note = LessonNote::create([
            'user_id' => $request->user()->id,
            'course_item_id' => $item->id,
            'course_id' => $item->course_id,
            'body' => $validated['body'],
            'video_timestamp_seconds' => $validated['video_timestamp_seconds'] ?? null,
        ]);

        return ApiResponse::created($note->only(['id', 'body', 'video_timestamp_seconds', 'created_at']));
    }

    public function destroy(Request $request, LessonNote $note): JsonResponse
    {
        // A note is private to its author; 404 rather than 403 so the endpoint
        // does not confirm someone else's note exists.
        if ($note->user_id !== $request->user()->id) {
            throw new NotFoundHttpException;
        }

        $note->delete();

        return ApiResponse::noContent();
    }

    private function assertAccess(Request $request, CourseItem $item): void
    {
        $decision = $this->access->forItem($request->user(), $item);

        if (! $decision->granted) {
            throw ContentLocked::from($decision);
        }
    }
}
