<?php

declare(strict_types=1);

namespace Database\Factories\Content;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Models\Post;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A DRAFT with a body — like every other publishable factory here, publishing
 * through `ChangePostStatus` is what fires the event, so a test about the event
 * must go through the endpoint.
 *
 * @extends Factory<Post>
 */
final class PostFactory extends Factory
{
    protected $model = Post::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->sentence(5);

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'author_id' => User::factory(),
            'title' => $title,
            'excerpt' => fake()->sentence(12),
            'body' => '<p>'.fake()->paragraph(4).'</p>',
            'status' => PostStatus::Draft,
            'published_at' => null,
        ];
    }

    /** Live since yesterday, and already announced — as the app would have. */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PostStatus::Published,
            'published_at' => now()->subDay(),
            'announced_at' => now()->subDay(),
        ]);
    }

    /** Published, but not until the day after tomorrow. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => PostStatus::Published,
            'published_at' => now()->addDays(2),
        ]);
    }
}
