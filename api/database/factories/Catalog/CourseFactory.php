<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Enums\CourseInstructorRole;
use App\Domain\Catalog\Enums\CourseLevel;
use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Database\Factories\Curriculum\CourseItemFactory;
use Database\Factories\Curriculum\CourseSectionFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(4, true));

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'subtitle' => fake()->sentence(6),
            // Long enough to clear the publish checklist by default; the
            // `incomplete` state is what exercises the failure path.
            'description' => fake()->paragraph(5),
            'owner_id' => User::factory()->withRole(RoleKey::Instructor),
            'category_id' => null,
            'thumbnail_media_id' => null,
            'intro_video_media_id' => null,
            'intro_video_url' => null,
            'level' => CourseLevel::All,
            'locale' => 'en',
            'status' => CourseStatus::Draft,
            'visibility' => CourseVisibility::Public,
            'completion_mode' => CompletionMode::Flexible,
            'pricing_model' => PricingModel::Free,
            'published_at' => null,
            'submitted_at' => null,
            'archived_at' => null,
            'coming_soon_at' => null,
            'review_note' => null,
            'section_count' => 0,
            'item_count' => 0,
            'total_duration_seconds' => 0,
            'enrollment_count' => 0,
            'rating_avg' => 0,
            'rating_count' => 0,
        ];
    }

    /** Creates the one-to-one rows and the owner seat the Action would. */
    public function configure(): static
    {
        return $this->afterCreating(function (Course $course): void {
            $course->detail()->firstOrCreate(['course_id' => $course->id]);
            $course->setting()->firstOrCreate(['course_id' => $course->id]);
            $course->instructors()->firstOrCreate(
                ['user_id' => $course->owner_id],
                ['role' => CourseInstructorRole::Owner, 'position' => 0],
            );
        });
    }

    /**
     * Adds a real curriculum. From Phase 5 the publish checklist requires one,
     * so anything asserting a successful publish needs this.
     */
    public function withCurriculum(int $sections = 2, int $itemsPerSection = 2): static
    {
        return $this->afterCreating(function (Course $course) use ($sections, $itemsPerSection): void {
            $position = 0;

            for ($s = 0; $s < $sections; $s++) {
                $section = CourseSectionFactory::new()->create([
                    'course_id' => $course->id,
                    'position' => $s,
                    'title' => 'Section '.($s + 1),
                ]);

                for ($i = 0; $i < $itemsPerSection; $i++) {
                    CourseItemFactory::new()->inSection($section)->create([
                        'position' => $position++,
                        'title' => 'Item '.($i + 1),
                    ]);
                }
            }

            CurriculumChanged::dispatch($course);
        });
    }

    /** Everything the publish checklist demands. */
    public function publishable(): static
    {
        return $this->withCategory()->withCurriculum();
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => CourseStatus::Published,
            'published_at' => now()->subDays(3),
        ])->withCategory();
        // NOTE: deliberately no curriculum — a published course fixture is used
        // by catalogue tests that care about listing, not about publishability.
    }

    public function inReview(): static
    {
        return $this->state(fn () => [
            'status' => CourseStatus::InReview,
            'submitted_at' => now()->subDay(),
        ])->withCategory();
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => CourseStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    public function unlisted(): static
    {
        return $this->state(fn () => ['visibility' => CourseVisibility::Unlisted]);
    }

    public function private(): static
    {
        return $this->state(fn () => ['visibility' => CourseVisibility::Private]);
    }

    /** Fails the publish checklist: too short, no category. */
    public function incomplete(): static
    {
        return $this->state(fn () => [
            'title' => 'Ok',
            'description' => 'Too short.',
            'category_id' => null,
        ]);
    }

    public function withCategory(): static
    {
        return $this->state(fn () => ['category_id' => CourseCategoryFactory::new()]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }
}
