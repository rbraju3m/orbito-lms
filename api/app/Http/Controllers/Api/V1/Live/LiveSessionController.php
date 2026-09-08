<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\Catalog\Models\Course;
use App\Domain\Live\Actions\CancelLiveSession;
use App\Domain\Live\Actions\CreateLiveSession;
use App\Domain\Live\Actions\RecordAttendance;
use App\Domain\Live\Actions\RescheduleLiveSession;
use App\Domain\Live\Enums\AttendanceSource;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Cohort;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Queries\SessionAudience;
use App\Http\Requests\Live\StoreLiveSessionRequest;
use App\Http\Resources\Live\LiveSessionResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Scheduling and joining live sessions.
 *
 * The join endpoint is a WRITE, deliberately. Following a link is the only
 * signal every provider has in common — the manual one cannot report anything
 * — so the click is what records attendance, and handing out the URL without
 * recording it would leave every roster empty.
 */
final class LiveSessionController
{
    public function index(Request $request, Course $course, SessionAudience $audience): JsonResponse
    {
        Gate::authorize('view', $course);

        $sessions = LiveSession::query()
            ->with(['host', 'cohort', 'recording'])
            ->where('course_id', $course->id)
            ->orderBy('starts_at')
            ->paginate($this->perPage($request));

        $userId = $request->user()->id;

        return ApiResponse::ok(
            LiveSessionResource::collection($sessions->through(
                fn (LiveSession $session) => new LiveSessionResource(
                    $session,
                    $audience->includes($session, $userId),
                ),
            )),
        );
    }

    public function store(
        StoreLiveSessionRequest $request,
        Course $course,
        CreateLiveSession $action,
    ): JsonResponse {
        Gate::authorize('manage-live-for-course', $course);

        $cohortId = $request->filled('cohort_id')
            ? Cohort::query()->where('uuid', $request->string('cohort_id'))->value('id')
            : null;

        $session = $action->handle($request->user(), [
            ...$request->validated(),
            'course_id' => $course->id,
            'cohort_id' => $cohortId,
        ]);

        return ApiResponse::created(
            new LiveSessionResource($session->load(['host', 'cohort']), canJoin: true),
        );
    }

    public function update(
        StoreLiveSessionRequest $request,
        LiveSession $session,
        RescheduleLiveSession $action,
    ): JsonResponse {
        $this->authorizeManage($session);

        return ApiResponse::ok(new LiveSessionResource(
            $action->handle($session, $request->validated())->load(['host', 'cohort']),
            canJoin: true,
        ));
    }

    public function destroy(LiveSession $session, CancelLiveSession $action): JsonResponse
    {
        $this->authorizeManage($session);

        // Cancelled, never deleted: the attendance, the recording and the fact
        // that it was called off are all things somebody may need later.
        return ApiResponse::ok(new LiveSessionResource(
            $action->handle($session)->load(['host', 'cohort']),
            canJoin: false,
        ));
    }

    /**
     * Following the link — and the only thing that records attendance.
     *
     * 422 rather than 403 when the moment has passed: they did nothing wrong,
     * and a 403 would read as "you are not allowed" to somebody who simply
     * arrived late.
     */
    public function join(
        Request $request,
        LiveSession $session,
        SessionAudience $audience,
        RecordAttendance $attendance,
    ): JsonResponse {
        $userId = $request->user()->id;

        abort_unless($audience->includes($session, $userId), 403);

        if (! $session->isJoinable() || $session->join_url === null) {
            throw LiveSessionRejected::notJoinable();
        }

        // The host is at their own class, not a learner attending it — their
        // presence is not a roster entry.
        if ($session->host_id !== $userId) {
            $attendance->handle($session, $userId, AttendanceSource::SelfJoin);
        }

        return ApiResponse::ok([
            'join_url' => $session->join_url,
            'session' => (new LiveSessionResource($session->load('host'), canJoin: true))
                ->resolve($request),
        ]);
    }

    public function leave(
        Request $request,
        LiveSession $session,
        RecordAttendance $attendance,
    ): JsonResponse {
        $attendance->leave($session, $request->user()->id);

        return ApiResponse::noContent();
    }

    private function authorizeManage(LiveSession $session): void
    {
        $course = $session->loadMissing('course')->course;

        if ($course === null) {
            // A webinar's session belongs to no course, so there is nothing
            // to scope against — the academy-wide key answers instead.
            Gate::authorize('manage-webinars');

            return;
        }

        Gate::authorize('manage-live-for-course', $course);
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
