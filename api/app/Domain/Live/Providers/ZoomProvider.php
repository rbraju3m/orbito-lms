<?php

declare(strict_types=1);

namespace App\Domain\Live\Providers;

use App\Domain\Live\Data\Meeting;
use App\Domain\Live\Data\MeetingRequest;
use App\Domain\Live\Data\ProviderAccount;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Zoom, through a Server-to-Server OAuth app.
 *
 * ⚠ THIS HAS NEVER CONTACTED ZOOM. It is written against the published API and
 * is unproven, exactly as `StripeGateway` was at the end of Phase 10 — an
 * integration cannot be proven without credentials, and pretending otherwise
 * is how a phase gets called complete and then fails on its first real use.
 * The manual provider is what works today.
 *
 * Server-to-Server OAuth rather than user OAuth, deliberately: an academy
 * connects ONE account and schedules on its behalf, so there is no per-host
 * consent flow, no refresh token to keep alive, and nothing to re-authorise
 * when the instructor who set it up leaves.
 */
final class ZoomProvider implements LiveSessionProvider
{
    private const API = 'https://api.zoom.us/v2';

    public function create(MeetingRequest $request, ProviderAccount $account): Meeting
    {
        $hostEmail = $request->hostEmail ?? $account->require('host_email');

        $response = Http::withToken($this->token($account))
            ->post(self::API."/users/{$hostEmail}/meetings", [
                'topic' => $request->title,
                'agenda' => $request->description,
                // 2 = scheduled. Recurring meetings are a different shape and
                // a cohort's weekly session is modelled as many sessions here,
                // not as one recurrence — the roster and the attendance are
                // per occurrence.
                'type' => 2,
                'start_time' => $request->startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration' => $request->durationMinutes(),
                'timezone' => $request->timezone,
                'settings' => [
                    // Learners arriving before the host see a waiting room
                    // rather than an empty room they assume is broken.
                    'join_before_host' => false,
                    'waiting_room' => true,
                ],
            ]);

        if ($response->failed()) {
            throw LiveSessionRejected::providerNotConnected('zoom');
        }

        return new Meeting(
            externalId: (string) $response->json('id'),
            joinUrl: (string) $response->json('join_url'),
            // NEVER handed to a learner: this link opens the meeting AS the
            // host, and the resource that renders a session omits it.
            hostUrl: $response->json('start_url'),
        );
    }

    public function update(string $externalId, MeetingRequest $request, ProviderAccount $account): Meeting
    {
        $response = Http::withToken($this->token($account))
            ->patch(self::API."/meetings/{$externalId}", [
                'topic' => $request->title,
                'agenda' => $request->description,
                'start_time' => $request->startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration' => $request->durationMinutes(),
                'timezone' => $request->timezone,
            ]);

        if ($response->failed()) {
            throw LiveSessionRejected::providerNotConnected('zoom');
        }

        // A PATCH returns 204, so the meeting has to be re-read for its URLs.
        $meeting = Http::withToken($this->token($account))->get(self::API."/meetings/{$externalId}");

        return new Meeting(
            externalId: $externalId,
            joinUrl: (string) $meeting->json('join_url'),
            hostUrl: $meeting->json('start_url'),
        );
    }

    public function cancel(string $externalId, ProviderAccount $account): void
    {
        $response = Http::withToken($this->token($account))
            ->delete(self::API."/meetings/{$externalId}");

        /*
         * A 404 is the outcome the caller wanted. Treating "already gone" as a
         * failure would leave the session cancelled here and live at Zoom,
         * which is the worst of both.
         */
        if ($response->failed() && $response->status() !== 404) {
            throw LiveSessionRejected::providerNotConnected('zoom');
        }
    }

    /**
     * A Server-to-Server OAuth token, cached just short of its life.
     *
     * Zoom issues these for an hour and rate-limits the token endpoint; asking
     * for a fresh one per call is how an academy scheduling a term of sessions
     * gets throttled. Fifty-five minutes leaves room for a slow request.
     */
    private function token(ProviderAccount $account): string
    {
        $accountId = $account->require('account_id');

        return Cache::remember(
            'live:zoom:token:'.md5($accountId),
            now()->addMinutes(55),
            function () use ($account, $accountId): string {
                $response = Http::asForm()
                    ->withBasicAuth($account->require('client_id'), $account->require('client_secret'))
                    ->post('https://zoom.us/oauth/token', [
                        'grant_type' => 'account_credentials',
                        'account_id' => $accountId,
                    ]);

                if ($response->failed()) {
                    throw LiveSessionRejected::providerNotConnected('zoom');
                }

                return (string) $response->json('access_token');
            },
        );
    }
}
