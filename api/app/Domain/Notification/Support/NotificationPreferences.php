<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\NotificationPreference;

/**
 * Which channels somebody is willing to hear a given type on.
 *
 * This class is both RENDERED and ENFORCED — the settings screen draws
 * `matrix()` and every delivery asks `channelsFor()`, so the switches a
 * person sees and the switches that actually decide cannot drift apart. Same
 * shape as PublishChecklist (§10) and SubmissionRules (§14).
 *
 * A missing row means the type's default. Nothing is written until somebody
 * moves a switch, which is why a new notification type needs no backfill.
 *
 * There is deliberately NO caching here. A worker sending an announcement to
 * five thousand learners issues one indexed lookup per recipient, in a
 * background job, which is the right place for it — and §15 has already been
 * paid twice for a memo that outlived the request it was built in.
 */
final class NotificationPreferences
{
    /**
     * The channels a delivery may actually use, as the strings Laravel's
     * `via()` routes on.
     *
     * @return list<string>
     */
    public function channelsFor(int $userId, NotificationType $type): array
    {
        $overrides = $this->overridesFor($userId, $type);

        $channels = [];

        foreach (NotificationChannel::cases() as $channel) {
            if ($this->allows($type, $channel, $overrides)) {
                $channels[] = $channel->value;
            }
        }

        return $channels;
    }

    /**
     * The whole settings screen, in one shape.
     *
     * Locked channels are returned rather than omitted, so the UI renders a
     * disabled switch that explains itself instead of a gap somebody reads as
     * a bug.
     *
     * @return list<array{
     *     key: string, label: string, description: string, group: string,
     *     channels: list<array{channel: string, label: string, enabled: bool, locked: bool}>
     * }>
     */
    public function matrix(int $userId): array
    {
        $overrides = $this->allOverridesFor($userId);

        $rows = [];

        foreach (NotificationType::cases() as $type) {
            $channels = [];

            foreach (NotificationChannel::cases() as $channel) {
                $channels[] = [
                    'channel' => $channel->value,
                    'label' => $channel->label(),
                    'enabled' => $this->allows($type, $channel, $overrides[$type->value] ?? []),
                    'locked' => $channel->isLocked(),
                ];
            }

            $rows[] = [
                'key' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'group' => $type->group()->value,
                'channels' => $channels,
            ];
        }

        return $rows;
    }

    /**
     * Move one switch.
     *
     * A locked channel is refused here as well as in the Form Request: the
     * request is the message to the caller, this is the guarantee.
     */
    public function set(
        int $userId,
        NotificationType $type,
        NotificationChannel $channel,
        bool $enabled,
    ): void {
        if ($channel->isLocked()) {
            return;
        }

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'event_key' => $type->value, 'channel' => $channel->value],
            ['enabled' => $enabled],
        );
    }

    /**
     * @param  array<string, bool>  $overrides  channel value => enabled
     */
    private function allows(NotificationType $type, NotificationChannel $channel, array $overrides): bool
    {
        if ($channel->isLocked()) {
            return true;
        }

        return $overrides[$channel->value] ?? $type->defaultsFor($channel);
    }

    /** @return array<string, bool> channel value => enabled */
    private function overridesFor(int $userId, NotificationType $type): array
    {
        return NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event_key', $type->value)
            ->get()
            ->mapWithKeys(fn (NotificationPreference $p) => [$p->channel->value => $p->enabled])
            ->all();
    }

    /** @return array<string, array<string, bool>> event key => channel => enabled */
    private function allOverridesFor(int $userId): array
    {
        $map = [];

        foreach (NotificationPreference::query()->where('user_id', $userId)->get() as $preference) {
            // A retired type leaves rows behind; skip what we no longer know
            // about rather than letting one stale row break the screen.
            if (NotificationType::tryFrom($preference->event_key) === null) {
                continue;
            }

            $map[$preference->event_key][$preference->channel->value] = $preference->enabled;
        }

        return $map;
    }
}
