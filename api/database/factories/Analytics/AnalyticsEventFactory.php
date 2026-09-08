<?php

declare(strict_types=1);

namespace Database\Factories\Analytics;

use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Enums\EventSource;
use App\Domain\Analytics\Models\AnalyticsEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsEvent>
 */
final class AnalyticsEventFactory extends Factory
{
    protected $model = AnalyticsEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => EventName::CourseViewed,
            'occurred_at' => now(),
            'actor_id' => null,
            'session_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'course_id' => null,
            'course_item_id' => null,
            'properties' => null,
            'ip_hash' => null,
            'source' => EventSource::Web,
        ];
    }

    public function named(EventName $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }

    public function at(CarbonInterface $moment): static
    {
        return $this->state(fn () => ['occurred_at' => $moment]);
    }

    public function forCourse(int $courseId): static
    {
        return $this->state(fn () => ['course_id' => $courseId]);
    }

    public function by(int $actorId): static
    {
        return $this->state(fn () => ['actor_id' => $actorId]);
    }
}
