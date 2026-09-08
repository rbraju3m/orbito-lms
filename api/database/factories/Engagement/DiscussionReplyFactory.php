<?php

declare(strict_types=1);

namespace Database\Factories\Engagement;

use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DiscussionReply>
 */
final class DiscussionReplyFactory extends Factory
{
    protected $model = DiscussionReply::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'discussion_id' => Discussion::factory(),
            'parent_id' => null,
            'user_id' => User::factory()->withRole(RoleKey::Student),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'is_instructor_reply' => false,
            'status' => DiscussionReply::STATUS_PUBLISHED,
        ];
    }

    public function fromInstructor(): static
    {
        return $this->state(fn () => ['is_instructor_reply' => true]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => DiscussionReply::STATUS_HIDDEN]);
    }
}
