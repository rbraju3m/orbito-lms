<?php

declare(strict_types=1);

namespace Database\Factories\Engagement;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Engagement\Models\Review;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Review>
 */
final class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'course_id' => Course::factory()->published(),
            'user_id' => User::factory()->withRole(RoleKey::Student),
            'enrollment_id' => null,
            'rating' => fake()->numberBetween(1, 5),
            'title' => fake()->sentence(4),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'status' => ReviewStatus::Published,
            'published_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => ReviewStatus::Pending,
            'published_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => ReviewStatus::Rejected,
            'published_at' => null,
        ]);
    }

    public function rated(int $rating): static
    {
        return $this->state(fn () => ['rating' => $rating]);
    }
}
