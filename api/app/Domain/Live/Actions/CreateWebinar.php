<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Events\WebinarCreated;
use App\Domain\Live\Models\Webinar;
use Illuminate\Support\Facades\DB;

/**
 * Creates a standalone live event and the session it happens at.
 *
 * Two rows, one act: a webinar with no session has no time, no link and
 * nothing to attend, so asking an academy to create one and then schedule the
 * other would leave an unpublishable half-thing behind every abandoned form.
 * The session is made by `CreateLiveSession` rather than inserted here, so a
 * webinar's meeting is created at the provider by exactly the same code as a
 * course's — including failing BEFORE anything is written when the provider
 * refuses.
 *
 * It is a session with no course and no cohort, which is what makes it
 * standalone. `SessionAudience` reads that as "everybody", and
 * `LiveSessionController::authorizeManage` falls through to the academy-wide
 * `manage-webinars` key because there is no course to scope against.
 *
 * Created as a DRAFT, always. Publishing is a separate decision with its own
 * endpoint, so nobody schedules an event into somebody's calendar by filling
 * in a form and pressing save.
 */
final class CreateWebinar
{
    public function __construct(private readonly CreateLiveSession $sessions) {}

    /** @param  array<string, mixed>  $attributes */
    public function handle(User $host, array $attributes): Webinar
    {
        return DB::transaction(function () use ($host, $attributes): Webinar {
            $session = $this->sessions->handle($host, [
                ...$attributes,
                'course_id' => null,
                'cohort_id' => null,
            ]);

            $webinar = Webinar::create([
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'capacity' => $attributes['capacity'] ?? null,
                // Free unless the academy says otherwise. A paid one gets its
                // (dormant) product from the event below, so it can be priced
                // while it is still a draft.
                'is_paid' => (bool) ($attributes['is_paid'] ?? false),
                'live_session_id' => $session->id,
                'status' => WebinarStatus::Draft,
            ]);

            WebinarCreated::dispatch($webinar);

            return $webinar;
        });
    }
}
