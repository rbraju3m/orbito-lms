<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Data\MeetingRequest;
use App\Domain\Live\Events\SessionScheduled;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Providers\LiveProviderFactory;
use Carbon\CarbonImmutable;

/**
 * Moves a session.
 *
 * RESETS `reminder_sent_at`, which is the whole reason this is its own action
 * rather than a `->update()`. A session moved from Tuesday to Thursday whose
 * reminder already went out would never send another, and everybody would
 * arrive on the wrong day holding an email that told them so.
 */
final class RescheduleLiveSession
{
    public function __construct(private readonly LiveProviderFactory $providers) {}

    /** @param  array<string, mixed>  $attributes */
    public function handle(LiveSession $session, array $attributes): LiveSession
    {
        $startsAt = CarbonImmutable::parse($attributes['starts_at'] ?? $session->starts_at);
        $endsAt = CarbonImmutable::parse($attributes['ends_at'] ?? $session->ends_at);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw LiveSessionRejected::endsBeforeItStarts();
        }

        $moved = ! $startsAt->equalTo($session->starts_at) || ! $endsAt->equalTo($session->ends_at);

        $changes = [
            'title' => $attributes['title'] ?? $session->title,
            'description' => $attributes['description'] ?? $session->description,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $attributes['timezone'] ?? $session->timezone,
        ];

        if ($session->provider->isHostSupplied()) {
            if (array_key_exists('join_url', $attributes)) {
                $changes['join_url'] = (string) $attributes['join_url'];
            }
        } elseif ($session->external_id !== null) {
            [$implementation, $account] = $this->providers->for($session->provider);

            $meeting = $implementation->update($session->external_id, new MeetingRequest(
                title: (string) $changes['title'],
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: (string) $changes['timezone'],
                description: $changes['description'],
                hostEmail: $session->loadMissing('host')->host?->email,
            ), $account);

            // Some providers reissue the join URL when the time changes, so
            // the stored one is replaced rather than assumed stable.
            $changes['join_url'] = $meeting->joinUrl;
            $changes['host_url'] = $meeting->hostUrl ?? $session->host_url;
        }

        if ($moved) {
            /*
             * The reminder has to go again. Without this, a session moved
             * after its reminder went out would never send another and
             * everybody would arrive on the old day.
             */
            $changes['reminder_sent_at'] = null;
        }

        $session->forceFill($changes)->save();

        SessionScheduled::dispatch($session->refresh(), isNew: false);

        return $session;
    }
}
