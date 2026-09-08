<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Domain\Analytics\Actions\RecordEvent;
use App\Domain\Analytics\Data\EventData;
use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Enums\EventSource;
use App\Domain\Analytics\Support\IpHasher;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Http\Requests\Analytics\TrackEventsRequest;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The one place a client may write to the event log.
 *
 * It exists because four things worth counting are invisible to the server: a
 * course page being looked at, a lesson being opened, a search, a basket
 * abandoned. Every other event in the vocabulary is raised by a listener on a
 * domain event, where it cannot be lied about.
 *
 * 202, not 201. The caller is told it was accepted, not what was written —
 * the response carries a count and nothing else, because a client has no
 * business reading the log back.
 */
final class TrackController
{
    public function __construct(private readonly RecordEvent $record) {}

    public function __invoke(TrackEventsRequest $request): JsonResponse
    {
        /** @var list<array<string, mixed>> $events */
        $events = $request->validated('events');

        $now = CarbonImmutable::now();
        $ipHash = IpHasher::hash($request->ip());

        /*
         * Resolve the uuids the client speaks in to the ids the log stores,
         * ONCE for the whole batch. A beacon carrying twenty item views of one
         * course would otherwise be forty lookups.
         */
        $courseIds = $this->resolve(Course::class, $events, 'course_id');
        $itemIds = $this->resolve(CourseItem::class, $events, 'course_item_id');

        $recorded = 0;

        foreach ($events as $event) {
            $result = $this->record->handle(new EventData(
                name: EventName::from((string) $event['name']),
                occurredAt: $this->occurredAt($event['occurred_at'] ?? null, $now),
                actorId: $request->user()?->id,
                sessionId: $event['session_id'] ?? null,
                courseId: $courseIds[$event['course_id'] ?? ''] ?? null,
                courseItemId: $itemIds[$event['course_item_id'] ?? ''] ?? null,
                properties: is_array($event['properties'] ?? null) ? $event['properties'] : [],
                ipHash: $ipHash,
                source: EventSource::tryFrom((string) ($event['source'] ?? '')) ?? EventSource::Web,
            ));

            if ($result !== null) {
                $recorded++;
            }
        }

        return ApiResponse::accepted(['recorded' => $recorded]);
    }

    /**
     * Clamps the client's clock.
     *
     * A device with a wrong year would otherwise write into next month's
     * report, where nothing would ever remove it. Anything in the future
     * becomes now; anything older than the batch could plausibly be — a day —
     * becomes now as well, because a beacon is not a backfill tool.
     */
    private function occurredAt(mixed $claimed, CarbonImmutable $now): CarbonImmutable
    {
        if (! is_string($claimed) || $claimed === '') {
            return $now;
        }

        $parsed = CarbonImmutable::parse($claimed);

        return $parsed->isAfter($now) || $parsed->isBefore($now->subDay()) ? $now : $parsed;
    }

    /**
     * @param  class-string<Course|CourseItem>  $model
     * @param  list<array<string, mixed>>  $events
     * @return array<string, int> uuid => id
     */
    private function resolve(string $model, array $events, string $key): array
    {
        $uuids = array_values(array_unique(array_filter(
            array_map(fn (array $event): mixed => $event[$key] ?? null, $events),
            is_string(...),
        )));

        if ($uuids === []) {
            return [];
        }

        /** @var array<string, int> $map */
        $map = $model::query()->whereIn('uuid', $uuids)->pluck('id', 'uuid')->all();

        return $map;
    }
}
