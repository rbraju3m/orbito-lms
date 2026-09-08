<?php

declare(strict_types=1);

namespace Database\Factories\Live;

use App\Domain\Identity\Models\User;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Live\Models\LiveSession;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LiveSession>
 */
final class LiveSessionFactory extends Factory
{
    protected $model = LiveSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'course_id' => null,
            'cohort_id' => null,
            'provider' => LiveProvider::Manual,
            'external_id' => null,
            'join_url' => 'https://meet.example.test/'.Str::random(10),
            'host_url' => null,
            'host_id' => User::factory()->instructor(),
            'title' => 'Week 1 live call',
            'description' => null,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'timezone' => 'Asia/Dhaka',
            'status' => SessionStatus::Scheduled,
        ];
    }

    public function startingAt(CarbonInterface $moment, int $minutes = 60): static
    {
        return $this->state(fn () => [
            'starts_at' => $moment,
            'ends_at' => $moment->copy()->addMinutes($minutes),
        ]);
    }

    /** A placeholder the author has not finished — no link yet. */
    public function withoutLink(): static
    {
        return $this->state(fn () => ['join_url' => null]);
    }
}
