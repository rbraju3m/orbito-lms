<?php

declare(strict_types=1);

namespace Database\Factories\Assessment;

use App\Domain\Assessment\Enums\SubmissionStatus;
use App\Domain\Assessment\Models\AssignmentSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssignmentSubmission>
 */
final class AssignmentSubmissionFactory extends Factory
{
    protected $model = AssignmentSubmission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'attempt_number' => 1,
            'status' => SubmissionStatus::Submitted,
            'body' => 'My answer.',
            'submitted_at' => now(),
            'is_late' => false,
            'late_penalty_points' => 0,
        ];
    }

    public function graded(float $points = 80): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::Graded,
            'points_raw' => $points,
            'points_earned' => $points,
            'graded_at' => now(),
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn () => [
            'status' => SubmissionStatus::Returned,
            'graded_at' => now(),
        ]);
    }
}
