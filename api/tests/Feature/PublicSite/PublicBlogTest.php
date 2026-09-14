<?php

declare(strict_types=1);

use App\Domain\Content\Models\Post;
use Tests\Concerns\SwitchesTenants;

/*
 * The academy's blog, to a stranger. Every request is made with no user and
 * no academy open (`tenancy()->end()`), because the harness leaves one open and
 * a public route tested without ending it passes while resolving nothing — see
 * PublicSiteTest. Fixtures are created first, while the academy is still open.
 */

uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();
});

it('lists what is live, newest first, without the bodies', function (): void {
    Post::factory()->published()->create(['title' => 'Older', 'published_at' => now()->subDays(5)]);
    Post::factory()->published()->create(['title' => 'Newer', 'published_at' => now()->subDay()]);
    Post::factory()->create(['title' => 'Still a draft']);
    Post::factory()->scheduled()->create(['title' => 'Not yet']);

    tenancy()->end();

    $response = $this->getJson('/api/v1/public/test-academy/posts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Newer')
        ->assertJsonPath('data.1.title', 'Older');

    expect($response->json('data.0'))->not->toHaveKey('body');
});

it('opens a live post by its address', function (): void {
    Post::factory()->published()->create([
        'slug' => 'why-watercolour',
        'title' => 'Why watercolour',
        'body' => '<p>Start wet.</p>',
        'seo_description' => 'Where every beginner should start.',
    ]);

    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/posts/why-watercolour')
        ->assertOk()
        ->assertJsonPath('data.title', 'Why watercolour')
        ->assertJsonPath('data.body', '<p>Start wet.</p>')
        ->assertJsonPath('data.seo_description', 'Where every beginner should start.')
        ->assertJsonPath('data.reading_minutes', 1);
});

it('gives a draft and a scheduled post the same 404 as an address that never existed', function (): void {
    Post::factory()->create(['slug' => 'not-finished']);
    Post::factory()->scheduled()->create(['slug' => 'friday']);

    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/posts/not-finished')->assertNotFound();
    $this->getJson('/api/v1/public/test-academy/posts/friday')->assertNotFound();
    $this->getJson('/api/v1/public/test-academy/posts/no-such-post')->assertNotFound();
});

it('shows a scheduled post on its own once its time comes', function (): void {
    Post::factory()->scheduled()->create(['slug' => 'friday']);

    // Nothing sweeps it into place: the query compares against the clock.
    $this->travel(3)->days();
    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/posts/friday')->assertOk();
});

it('tells a stranger nothing that belongs to the people writing the blog', function (): void {
    Post::factory()->published()->create(['slug' => 'why-watercolour']);

    tenancy()->end();

    $post = $this->getJson('/api/v1/public/test-academy/posts/why-watercolour')->assertOk()->json('data');
    $listed = $this->getJson('/api/v1/public/test-academy/posts')->assertOk()->json('data.0');

    // Absent rather than false: a stranger has no draft to ask about.
    foreach (['status', 'status_label', 'is_scheduled', 'can_edit_slug', 'cover_media_ref', 'created_at', 'updated_at'] as $key) {
        expect($post)->not->toHaveKey($key)
            ->and($listed)->not->toHaveKey($key);
    }
});
