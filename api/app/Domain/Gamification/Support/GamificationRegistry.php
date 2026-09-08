<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Support;

use App\Domain\Gamification\Models\Badge;
use App\Domain\Gamification\Models\GamificationRule;

/**
 * Seeds an academy's rules and badges from config.
 *
 * The same shape as PermissionRegistry: config is the SEED, not the source of
 * truth. An academy retunes points, deactivates a rule, renames a badge — and
 * a re-sync must not undo any of it, so this only ever CREATES what is
 * missing. Nothing here updates and nothing here deletes.
 *
 * That is the whole design decision. A sync that overwrote would make the
 * config file the truth and every academy's tuning a thing that silently
 * reverts on deploy.
 */
final class GamificationRegistry
{
    /** @return array{rules: int, badges: int} how many were created */
    public function sync(): array
    {
        return ['rules' => $this->syncRules(), 'badges' => $this->syncBadges()];
    }

    private function syncRules(): int
    {
        $existing = GamificationRule::query()->pluck('key')->all();
        $created = 0;

        /** @var array<int, array<string, mixed>> $rules */
        $rules = config('gamification.rules', []);

        foreach ($rules as $rule) {
            if (in_array($rule['key'], $existing, true)) {
                continue;
            }

            GamificationRule::create([
                'key' => $rule['key'],
                'event_name' => $rule['event_name'],
                'name' => $rule['name'],
                'points' => $rule['points'],
                'conditions' => $rule['conditions'] ?? [],
                'is_active' => true,
                'cooldown_seconds' => $rule['cooldown_seconds'] ?? 0,
                'max_per_day' => $rule['max_per_day'] ?? null,
            ]);

            $created++;
        }

        return $created;
    }

    private function syncBadges(): int
    {
        $existing = Badge::query()->pluck('key')->all();
        $created = 0;

        /** @var array<int, array<string, mixed>> $badges */
        $badges = config('gamification.badges', []);

        foreach ($badges as $badge) {
            if (in_array($badge['key'], $existing, true)) {
                continue;
            }

            Badge::create([
                'key' => $badge['key'],
                'name' => $badge['name'],
                'description' => $badge['description'] ?? null,
                'tier' => $badge['tier'],
                'criteria' => $badge['criteria'],
                'is_active' => true,
            ]);

            $created++;
        }

        return $created;
    }
}
