<?php

declare(strict_types=1);

namespace Database\Factories\Content;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\Page;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A DRAFT with one heading — a page with something in it, so a test that
 * publishes it is not refused for being empty.
 *
 * @extends Factory<Page>
 */
final class PageFactory extends Factory
{
    protected $model = Page::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'author_id' => User::factory(),
            'title' => Str::title($title),
            'blocks' => [
                ['id' => (string) Str::uuid(), 'type' => 'heading', 'props' => ['text' => 'Welcome', 'level' => 2]],
            ],
            'status' => PageStatus::Draft,
            'published_at' => null,
            'show_in_nav' => false,
            'home_key' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PageStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    /** The academy's front page. */
    public function home(): static
    {
        return $this->state(fn (): array => ['home_key' => Page::HOME]);
    }

    /** @param  list<array{id: string, type: string, props: array<string, mixed>}>  $blocks */
    public function withBlocks(array $blocks): static
    {
        return $this->state(fn (): array => ['blocks' => $blocks]);
    }
}
