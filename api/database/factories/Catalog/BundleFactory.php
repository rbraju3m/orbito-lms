<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A DRAFT bundle by default, like every other lifecycle factory here
 * (`AnnouncementFactory`, `CohortFactory`, `WebinarFactory`). Publishing runs
 * the checklist and fires the event, which is exactly what a test that wants
 * a published bundle should be exercising.
 *
 * @extends Factory<Bundle>
 */
final class BundleFactory extends Factory
{
    protected $model = Bundle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(3, true)).' bundle';

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'subtitle' => fake()->sentence(6),
            // Long enough to clear the checklist; `incomplete()` is what
            // exercises the failure path.
            'description' => fake()->paragraph(4),
            'status' => BundleStatus::Draft,
            'published_at' => null,
        ];
    }

    /**
     * Published WITHOUT running the checklist — for tests that need a live
     * bundle as a fixture rather than as the thing under test.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => BundleStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => BundleStatus::Archived]);
    }

    /** Nothing the checklist wants: no description, and no courses added. */
    public function incomplete(): static
    {
        return $this->state(fn (): array => ['description' => null, 'subtitle' => null]);
    }

    /**
     * With these courses in it, in the order given.
     *
     * @param  list<Course>  $courses
     */
    public function containing(array $courses): static
    {
        return $this->afterCreating(function (Bundle $bundle) use ($courses): void {
            foreach ($courses as $position => $course) {
                $bundle->items()->create([
                    'course_id' => $course->id,
                    'position' => $position,
                ]);
            }
        });
    }
}
