<?php

declare(strict_types=1);

namespace Database\Factories\Enrollment;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Actions\RecalculateCourseProgress;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Enrollment>
 */
final class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'course_id' => Course::factory()->published(),
            'user_id' => User::factory()->withRole(RoleKey::Student),
            'status' => EnrollmentStatus::Active,
            'source' => EnrollmentSource::Free,
            'source_id' => null,
            'enrolled_at' => now(),
            'starts_at' => null,
            'expires_at' => null,
            'completed_at' => null,
            'suspended_at' => null,
            'suspended_reason' => null,
        ];
    }

    /** Creates the course_progress row the Action would. */
    public function configure(): static
    {
        return $this->afterCreating(
            fn (Enrollment $enrollment) => app(RecalculateCourseProgress::class)->handle($enrollment)
        );
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => EnrollmentStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
