<?php

declare(strict_types=1);

namespace Database\Factories\Curriculum;

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseSection>
 */
final class CourseSectionFactory extends Factory
{
    protected $model = CourseSection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => ucfirst(fake()->words(3, true)),
            'description' => null,
            'position' => 0,
        ];
    }
}
