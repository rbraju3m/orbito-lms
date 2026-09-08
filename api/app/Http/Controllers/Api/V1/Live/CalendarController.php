<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Queries\CalendarQuery;
use App\Http\Resources\Live\LiveSessionResource;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What is in somebody's diary.
 *
 * Not paginated, and bounded by the range instead — a calendar is read a month
 * at a time and a page boundary in the middle of a week is meaningless. The
 * window is capped so nobody can ask for a decade.
 */
final class CalendarController
{
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, CalendarQuery $calendar): JsonResponse
    {
        $from = $this->date($request->query('from'), CarbonImmutable::now('UTC')->startOfDay());
        $to = $this->date(
            $request->query('to'),
            $from->addDays(31),
        );

        if ($to->lessThanOrEqualTo($from)) {
            $to = $from->addDays(31);
        }

        // A quarter is as far as anybody plans, and it keeps the query bounded.
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS);
        }

        $sessions = $calendar->forUser($request->user()->id, $from, $to);

        return ApiResponse::ok([
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'sessions' => $sessions
                // Everything here is already the caller's own diary, so they
                // are in the audience by construction.
                ->map(fn (LiveSession $session) => (new LiveSessionResource($session, canJoin: true))
                    ->resolve($request))
                ->all(),
        ]);
    }

    private function date(mixed $value, CarbonImmutable $default): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        return CarbonImmutable::parse($value, 'UTC');
    }
}
