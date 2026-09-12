<?php

declare(strict_types=1);

namespace App\Domain\Live\Providers;

use App\Domain\Live\Data\Meeting;
use App\Domain\Live\Data\MeetingRequest;
use App\Domain\Live\Data\ProviderAccount;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

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
 * The academy connects a SERVICE ACCOUNT with domain-wide delegation, so the
 * event is owned by the academy rather than by whichever instructor happened
 * to authorise it — and so that connecting is a thing an academy does once.
 * This mints its own access token from that key, for the reason Zoom does:
 * Google's tokens live an hour, so a stored one would work until lunchtime
 * and then fail on a class nobody was watching.
 */
final class GoogleMeetProvider implements LiveSessionProvider
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Creating and moving events is all this does; it never reads a calendar. */
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public function create(MeetingRequest $request, ProviderAccount $account): Meeting
    {
        $calendarId = $account->get('calendar_id') ?? 'primary';

        $response = Http::withToken($this->token($account))
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

        $response = Http::withToken($this->token($account))
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

        $response = Http::withToken($this->token($account))
            ->delete(self::API."/calendars/{$calendarId}/events/{$externalId}");

        // Already gone is the outcome we wanted. Google answers 410 for an
        // event deleted twice.
        if ($response->failed() && ! in_array($response->status(), [404, 410], true)) {
            throw LiveSessionRejected::providerNotConnected('google_meet');
        }
    }

    /**
     * A short-lived access token, minted from the service account key.
     *
     * Cached just short of its hour and keyed on the CREDENTIALS, so rotating
     * a key takes effect at once rather than whenever the cache happens to
     * expire.
     */
    private function token(ProviderAccount $account): string
    {
        $email = $account->require('client_email');
        $privateKey = $account->require('private_key');
        $subject = $account->get('subject');

        return Cache::remember(
            'live:google_meet:token:'.hash('sha256', $email.'|'.$privateKey.'|'.($subject ?? '')),
            now()->addMinutes(55),
            function () use ($email, $privateKey, $subject): string {
                $response = Http::asForm()->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->assertion($email, $privateKey, $subject),
                ]);

                if ($response->failed()) {
                    throw LiveSessionRejected::providerNotConnected('google_meet');
                }

                return (string) $response->json('access_token');
            },
        );
    }

    /**
     * The signed JWT Google swaps for a token.
     *
     * `sub` is the delegation: without it the service account acts as itself,
     * which owns no calendar anybody in the academy can see.
     */
    private function assertion(string $email, string $privateKey, ?string $subject): string
    {
        $issuedAt = time();

        $claims = [
            'iss' => $email,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ];

        if ($subject !== null && $subject !== '') {
            $claims['sub'] = $subject;
        }

        try {
            $payload = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$this->encode($claims);
        } catch (JsonException) {
            throw LiveSessionRejected::providerNotConnected('google_meet');
        }

        /*
         * A key pasted out of the JSON file arrives with literal backslash-n
         * rather than newlines, which OpenSSL rejects with a message about a
         * PEM header nobody can act on. Repaired here rather than asked of the
         * person connecting.
         */
        $key = openssl_pkey_get_private(str_replace('\\n', "\n", $privateKey));

        if ($key === false || ! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw LiveSessionRejected::providerNotConnected('google_meet');
        }

        return $payload.'.'.$this->base64Url($signature);
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
