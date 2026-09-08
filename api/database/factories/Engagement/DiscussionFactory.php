<?php

declare(strict_types=1);

namespace Database\Factories\Engagement;

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Enums\DiscussionStatus;
use App\Domain\Engagement\Enums\DiscussionType;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Discussion>
 */
final class DiscussionFactory extends Factory
{
    protected $model = Discussion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'course_id' => Course::factory()->published(),
            'course_item_id' => null,
            'user_id' => User::factory()->withRole(RoleKey::Student),
            'type' => DiscussionType::Question,
            'title' => fake()->sentence(6),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'status' => DiscussionStatus::Open,
            'is_pinned' => false,
            'reply_count' => 0,
            'last_reply_at' => null,
            'accepted_reply_id' => null,
        ];
    }

    public function comment(): static
    {
        return $this->state(fn () => ['type' => DiscussionType::Comment]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => DiscussionStatus::Hidden]);
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }
}
