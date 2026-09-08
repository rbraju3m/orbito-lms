<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Data\MeetingRequest;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Live\Events\SessionScheduled;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Providers\LiveProviderFactory;
use Carbon\CarbonImmutable;

/**
 * Schedules one meeting.
 *
 * The provider call happens BEFORE the row is written, deliberately. A session
 * saved first and then failing at Zoom would leave a row in the schedule with
 * no way to join it — visible to learners, in their calendar, dead. Failing
 * first means the academy is told at the moment of scheduling, which is the
 * only moment anybody can do anything about it.
 */
final class CreateLiveSession
{
    public function __construct(private readonly LiveProviderFactory $providers) {}

    /** @param  array<string, mixed>  $attributes */
    public function handle(User $host, array $attributes): LiveSession
    {
        $provider = $attributes['provider'] instanceof LiveProvider
            ? $attributes['provider']
            : LiveProvider::from((string) $attributes['provider']);

        $startsAt = CarbonImmutable::parse($attributes['starts_at']);
        $endsAt = CarbonImmutable::parse($attributes['ends_at']);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw LiveSessionRejected::endsBeforeItStarts();
        }

        $timezone = (string) ($attributes['timezone'] ?? 'UTC');

        [$joinUrl, $hostUrl, $externalId] = $this->resolveMeeting(
            $provider,
            $attributes,
            new MeetingRequest(
                title: (string) $attributes['title'],
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $timezone,
                description: $attributes['description'] ?? null,
                hostEmail: $host->email,
            ),
        );

        $session = LiveSession::create([
            'course_id' => $attributes['course_id'] ?? null,
            'cohort_id' => $attributes['cohort_id'] ?? null,
            'provider' => $provider,
            'external_id' => $externalId,
            'join_url' => $joinUrl,
            'host_url' => $hostUrl,
            'host_id' => $host->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
            'status' => SessionStatus::Scheduled,
        ]);

        SessionScheduled::dispatch($session);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function resolveMeeting(LiveProvider $provider, array $attributes, MeetingRequest $request): array
    {
        if ($provider->isHostSupplied()) {
            $joinUrl = (string) ($attributes['join_url'] ?? '');

            if ($joinUrl === '') {
                throw LiveSessionRejected::linkRequired();
            }

            return [$joinUrl, null, null];
        }

        [$implementation, $account] = $this->providers->for($provider);
        $meeting = $implementation->create($request, $account);

        return [$meeting->joinUrl, $meeting->hostUrl, $meeting->externalId];
    }
}
