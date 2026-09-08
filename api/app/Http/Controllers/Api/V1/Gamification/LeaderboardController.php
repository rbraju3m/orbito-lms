<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Gamification;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Queries\CourseAccess;
use App\Domain\Gamification\Enums\LeaderboardPeriod;
use App\Domain\Gamification\Enums\LeaderboardScope;
use App\Domain\Gamification\Models\LeaderboardSnapshot;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The boards.
 *
 * Reads a SNAPSHOT and never computes one. A board built on request is a sum
 * over the whole ledger per page load, and it would reshuffle under somebody
 * while they read it.
 *
 * The caller's own row is returned separately — `me` — even when they are off
 * the bottom of the board. "You are 137th" is the only thing on this screen
 * that is useful to somebody who is not in the top fifty, and computing it
 * client-side is impossible when they are not in the payload.
 */
final class LeaderboardController
{
    public function index(Request $request): JsonResponse
    {
        $period = $this->period($request);
        $snapshot = $this->snapshot(LeaderboardScope::Global, null, $period);

        return ApiResponse::ok($this->render($snapshot, $period, $request->user()->id));
    }

    /**
     * A course's board.
     *
     * Gated on ACCESS, not on the catalogue: the people ranked here are the
     * people in the room, and a stranger browsing the course page has no
     * business reading a list of their classmates by name.
     */
    public function course(Request $request, Course $course, CourseAccess $access): JsonResponse
    {
        abort_unless($access->for($request->user(), $course)->granted, 403);

        $period = $this->period($request);
        $snapshot = $this->snapshot(LeaderboardScope::Course, $course->id, $period);

        return ApiResponse::ok([
            'course' => ['id' => $course->uuid, 'title' => $course->title],
            ...$this->render($snapshot, $period, $request->user()->id),
        ]);
    }

    /** @return array<string, mixed> */
    private function render(?LeaderboardSnapshot $snapshot, LeaderboardPeriod $period, int $userId): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $snapshot === null ? [] : $snapshot->entries;

        $mine = null;

        foreach ($entries as $entry) {
            if ((int) $entry['user_id'] === $userId) {
                $mine = $entry;
                break;
            }
        }

        return [
            'period' => $period->value,
            'period_label' => $period->label(),
            // Always UTC, like every other window in this system.
            'period_start' => $snapshot?->period_start->toDateString(),
            // A snapshot, so how stale it is belongs in the payload.
            'computed_at' => $snapshot?->computed_at?->toIso8601String(),
            'entries' => array_map(fn (array $entry): array => [
                'rank' => (int) $entry['rank'],
                'name' => (string) $entry['name'],
                'points' => (int) $entry['points'],
                // So the UI can highlight the caller without knowing their id.
                'is_you' => (int) $entry['user_id'] === $userId,
            ], $entries),
            /*
             * Null means either "not ranked yet" or "opted out", and the API
             * deliberately does not distinguish: the second is nobody else's
             * business, and the client already knows its own setting.
             */
            'me' => $mine === null ? null : [
                'rank' => (int) $mine['rank'],
                'points' => (int) $mine['points'],
            ],
        ];
    }

    private function snapshot(LeaderboardScope $scope, ?int $scopeId, LeaderboardPeriod $period): ?LeaderboardSnapshot
    {
        return LeaderboardSnapshot::query()
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->where('period', $period)
            ->where('period_start', $period->startFor(CarbonImmutable::now('UTC'))->toDateString())
            ->first();
    }

    private function period(Request $request): LeaderboardPeriod
    {
        $request->validate(['period' => ['sometimes', Rule::enum(LeaderboardPeriod::class)]]);

        // Weekly by default. An all-time board nobody new can appear on stops
        // being a competition and becomes a list of who joined early.
        return LeaderboardPeriod::tryFrom((string) $request->query('period')) ?? LeaderboardPeriod::Weekly;
    }
}
