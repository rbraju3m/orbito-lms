<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Data\EventData;
use App\Domain\Analytics\Models\AnalyticsEvent;
use Throwable;

/**
 * Appends one row to the log.
 *
 * The single write path — listeners, the client endpoint and any future
 * backfill all come through here, so the shape of a row is decided once.
 *
 * IT NEVER THROWS INTO ITS CALLER. Analytics observes the system; it must not
 * be able to break it. A listener is queued and would retry, but the client
 * endpoint is not, and a full disk or a lock timeout must not turn a page view
 * into a 500 on somebody's lesson. The failure is reported and the request
 * carries on, because a missing row in a traffic count is a smaller problem
 * than a broken page.
 */
final class RecordEvent
{
    public function handle(EventData $data): ?AnalyticsEvent
    {
        try {
            return AnalyticsEvent::create([
                'name' => $data->name,
                // The caller's clock when it knows better — a queued listener
                // lands minutes after the thing it describes.
                'occurred_at' => $data->occurredAt ?? now(),
                'actor_id' => $data->actorId,
                'session_id' => $data->sessionId,
                'subject_type' => $data->subjectType,
                'subject_id' => $data->subjectId,
                'course_id' => $data->courseId,
                'course_item_id' => $data->courseItemId,
                'properties' => $data->properties === [] ? null : $data->properties,
                'ip_hash' => $data->ipHash,
                'source' => $data->source,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
