<?php

declare(strict_types=1);

namespace Database\Factories\Live;

use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Models\Webinar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webinar>
 */
final class WebinarFactory extends Factory
{
    protected $model = Webinar::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = 'Open evening: '.fake()->words(2, true);

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::random(6),
            'title' => $title,
            'description' => 'An hour on how the course works.',
            'live_session_id' => null,
            'capacity' => null,
            'is_paid' => false,
            'product_id' => null,
            'status' => WebinarStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => WebinarStatus::Published]);
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(fn () => ['capacity' => $capacity]);
    }
}
