<?php

declare(strict_types=1);

namespace Database\Factories\Curriculum;

use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Curriculum\Models\Lesson;
use App\Domain\Curriculum\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CourseItem>
 */
final class CourseItemFactory extends Factory
{
    protected $model = CourseItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $section = CourseSection::factory()->create();
        $lesson = Lesson::create([]);

        return [
            'uuid' => (string) Str::uuid7(),
            'course_id' => $section->course_id,
            'section_id' => $section->id,
            'position' => 0,
            'type' => ItemType::Lesson,
            'itemable_type' => $lesson->getMorphClass(),
            'itemable_id' => $lesson->id,
            'title' => ucfirst(fake()->words(4, true)),
            'is_preview' => false,
            'is_published' => true,
            'duration_seconds' => 0,
            'drip_available_at' => null,
            'drip_after_days' => null,
            'drip_after_item_id' => null,
        ];
    }

    public function inSection(CourseSection $section): static
    {
        return $this->state(fn () => [
            'section_id' => $section->id,
            'course_id' => $section->course_id,
        ]);
    }

    public function resource(): static
    {
        return $this->state(function (): array {
            $resource = Resource::create([]);

            return [
                'type' => ItemType::Resource,
                'itemable_type' => $resource->getMorphClass(),
                'itemable_id' => $resource->id,
            ];
        });
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }

    public function preview(): static
    {
        return $this->state(fn () => ['is_preview' => true]);
    }
}
