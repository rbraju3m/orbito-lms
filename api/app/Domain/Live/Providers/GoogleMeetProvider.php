<?php

declare(strict_types=1);

namespace App\Domain\Live\Providers;

use App\Domain\Live\Data\Meeting;
use App\Domain\Live\Data\MeetingRequest;
use App\Domain\Live\Data\ProviderAccount;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Meet, through the Calendar API.
 *
 * ⚠ THIS HAS NEVER CONTACTED GOOGLE. Written against the published API and
 * unproven — see the note on ZoomProvider.
 *
 * There is no "create a Meet" endpoint: a Meet link is a CONFERENCE ATTACHED
 * TO A CALENDAR EVENT, which is why this talks to Calendar and why the
 * `conferenceDataVersion=1` parameter is load-bearing. Omitting it makes the
 * request succeed and silently return an event with no meeting attached —
 * which is discovered by a class of forty at the appointed hour.
 *
 * The academy connects a service account with domain-wide delegation, so the
 * event is owned by the academy rather than by whichever instructor happened
 * to authorise it.
 */
final class GoogleMeetProvider implements LiveSessionProvider
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    public function create(MeetingRequest $request, ProviderAccount $account): Meeting
    {
        $calendarId = $account->get('calendar_id') ?? 'primary';

        $response = Http::withToken($account->require('access_token'))
            ->post(self::API."/calendars/{$calendarId}/events?conferenceDataVersion=1", [
                'summary' => $request->title,
                'description' => $request->description,
                'start' => [
                    'dateTime' => $request->startsAt->toIso8601String(),
                    'timeZone' => $request->timezone,
                ],
                'end' => [
                    'dateTime' => $request->endsAt->toIso8601String(),
                    'timeZone' => $request->timezone,
                ],
                'conferenceData' => [
                    'createRequest' => [
                        // Idempotency: Google dedupes on this, so a retried
                        // request cannot produce two meetings for one session.
                        'requestId' => (string) Str::uuid7(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw LiveSessionRejected::providerNotConnected('google_meet');
        }

        $joinUrl = $response->json('hangoutLink');

        if (! is_string($joinUrl) || $joinUrl === '') {
            /*
             * The event was created and has no conference on it — the failure
             * mode `conferenceDataVersion` exists to prevent. Refused here so
             * it surfaces to the person scheduling rather than to the class.
             */
            throw LiveSessionRejected::providerNotConnected('google_meet');
        }

        return new Meeting(
            externalId: (string) $response->json('id'),
            joinUrl: $joinUrl,
            // Meet has no separate host link: whoever owns the calendar event
            // is the host, and everybody else follows the same URL.
            hostUrl: null,
        );
    }

    public function update(string $externalId, MeetingRequest $request, ProviderAccount $account): Meeting
    {
        $calendarId = $account->get('calendar_id') ?? 'primary';

        $response = Http::withToken($account->require('access_token'))
            ->patch(self::API."/calendars/{$calendarId}/events/{$externalId}", [
                'summary' => $request->title,
                'description' => $request->description,
                'start' => [
                    'dateTime' => $request->startsAt->toIso8601String(),
                    'timeZone' => $request->timezone,
                ],
                'end' => [
                    'dateTime' => $request->endsAt->toIso8601String(),
                    'timeZone' => $request->timezone,
                ],
            ]);

        if ($response->failed()) {
            throw LiveSessionRejected::providerNotConnected('google_meet');
        }

        return new Meeting(
            externalId: $externalId,
            // Moving an event keeps its conference, so the link is stable.
            joinUrl: (string) $response->json('hangoutLink'),
        );
    }

    public function cancel(string $externalId, ProviderAccount $account): void
    {
        $calendarId = $account->get('calendar_id') ?? 'primary';

        $response = Http::withToken($account->require('access_token'))
            ->delete(self::API."/calendars/{$calendarId}/events/{$externalId}");

        // Already gone is the outcome we wanted. Google answers 410 for an
        // event deleted twice.
        if ($response->failed() && ! in_array($response->status(), [404, 410], true)) {
            throw LiveSessionRejected::providerNotConnected('google_meet');
        }
    }
}
