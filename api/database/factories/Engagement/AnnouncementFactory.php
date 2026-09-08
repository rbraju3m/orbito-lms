<?php

declare(strict_types=1);

namespace Database\Factories\Engagement;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Models\Announcement;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Announcement>
 */
final class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'course_id' => Course::factory()->published(),
            'author_id' => User::factory()->instructor(),
            'title' => fake()->sentence(5),
            'body' => '<p>'.fake()->paragraph().'</p>',
            // A draft by default: publishing is the deliberate act.
            'published_at' => null,
            'notify' => true,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['published_at' => now()]);
    }

    /** Written now, visible later. */
    public function scheduled(): static
    {
        return $this->state(fn () => ['published_at' => now()->addDay()]);
    }
}
