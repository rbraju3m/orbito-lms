<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Actions\RecordAttendance;
use App\Domain\Live\Enums\AttendanceSource;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\SessionAttendance;
use App\Domain\Live\Queries\SessionAudience;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The roster.
 *
 * The whole audience is returned, present and absent together — a list of only
 * the people who turned up cannot answer "who missed it?", which is the
 * question a roster is usually opened for.
 */
final class AttendanceController
{
    public function index(LiveSession $session, SessionAudience $audience): JsonResponse
    {
        $this->authorizeRoster($session);

        $expected = $audience->forSession($session);

        $present = SessionAttendance::query()
            ->where('live_session_id', $session->id)
            ->get()
            ->keyBy('user_id');

        /*
         * Names come from the CENTRAL users table, resolved with one
         * `whereIn` rather than a join — the boundary cannot be crossed in a
         * single statement (§ Multi-tenancy).
         */
        $names = User::query()->whereIn('id', $expected)->pluck('name', 'id');

        return ApiResponse::ok([
            'session' => ['id' => $session->uuid, 'title' => $session->title],
            'expected' => count($expected),
            'present' => $present->count(),
            'roster' => array_map(fn (int $userId): array => [
                'user_id' => $userId,
                'name' => $names[$userId] ?? 'Former member',
                'attended' => $present->has($userId),
                'joined_at' => $present->get($userId)?->joined_at?->toIso8601String(),
                'duration_seconds' => $present->get($userId)?->duration_seconds,
                // Which kind of evidence. A click and a host's word are not
                // the same thing, and a report that cannot say which is a
                // report nobody can defend.
                'source' => $present->get($userId)?->source->value,
            ], $expected),
        ]);
    }

    /**
     * A host marking somebody present.
     *
     * OVERRIDES a click, never the other way round: a person saying "they were
     * there" is better evidence than a link being opened, and somebody whose
     * connection died two minutes in should not be marked absent by the
     * system that watched them try.
     */
    public function store(
        Request $request,
        LiveSession $session,
        RecordAttendance $attendance,
    ): JsonResponse {
        $this->authorizeRoster($session);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer'],
        ]);

        foreach ($validated['user_ids'] as $userId) {
            $attendance->handle($session, (int) $userId, AttendanceSource::Host);
        }

        return ApiResponse::ok(['marked' => count($validated['user_ids'])]);
    }

    private function authorizeRoster(LiveSession $session): void
    {
        Gate::authorize('mark-attendance');

        $course = $session->loadMissing('course')->course;

        if ($course !== null) {
            // Holding `attendance.mark` globally is not permission to read
            // another course's roster — the § Authorization trap again.
            Gate::authorize('manage-live-for-course', $course);
        }
    }
}
