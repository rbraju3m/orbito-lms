<?php

declare(strict_types=1);

namespace Database\Factories\Live;

use App\Domain\Catalog\Models\Course;
use App\Domain\Live\Enums\CohortStatus;
use App\Domain\Live\Models\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cohort>
 */
final class CohortFactory extends Factory
{
    protected $model = Cohort::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'course_id' => Course::factory()->published(),
            'name' => 'Autumn intake',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeeks(9),
            'timezone' => 'Asia/Dhaka',
            'capacity' => null,
            'enrollment_deadline' => null,
            // Draft by default, like every other thing that can be published:
            // opening a run to the public is the deliberate act.
            'status' => CohortStatus::Draft,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => CohortStatus::Open]);
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(fn () => ['capacity' => $capacity]);
    }
}
