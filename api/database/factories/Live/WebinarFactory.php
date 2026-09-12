<?php

declare(strict_types=1);

namespace Database\Factories\Live;

use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Models\LiveSession;
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

    /**
     * The session it happens at — a live session with no course and no
     * cohort, which is what makes a webinar standalone.
     *
     * The default leaves it null, and that is the factory lying on purpose
     * (§ Traps that are still live): a webinar with no time is exactly the
     * thing `ChangeWebinarStatus` refuses to publish, and a test for that
     * needs to be able to build one. `CreateWebinar` always makes both.
     */
    public function withSession(): static
    {
        return $this->state(fn (): array => [
            'live_session_id' => LiveSession::factory()->create([
                'course_id' => null,
                'cohort_id' => null,
            ])->id,
        ]);
    }
}
