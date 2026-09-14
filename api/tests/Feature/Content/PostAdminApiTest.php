<?php

declare(strict_types=1);

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Events\PostPublished;
use App\Domain\Content\Models\Post;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\Event;

/*
 * Writing the academy's blog (`post.manage` — Admin and Super Admin). A post
 * speaks for the academy on its public site, so holding a course does not let
 * an instructor publish in its name.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
});

it('starts a draft with an address made from its title, and cleans the body', function (): void {
    $body = $this->actingAs($this->admin)->postJson('/api/v1/admin/posts', [
        'title' => 'Why we teach watercolour first',
        'body' => '<p>Start wet.</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
    ])->assertCreated()->json('data');

    expect($body)->toMatchArray([
        'slug' => 'why-we-teach-watercolour-first',
        'status' => 'draft',
        'is_scheduled' => false,
        'can_edit_slug' => true,
        'published_at' => null,
    ])
        // Sanitised on WRITE: the stored body is safe to render anywhere.
        ->and($body['body'])->toContain('<p>Start wet.</p>')
        ->and($body['body'])->not->toContain('script')
        ->and($body['body'])->not->toContain('onerror');

    expect(Post::query()->sole()->author_id)->toBe($this->admin->id);
});

it('gives a second post with the same title an address of its own', function (): void {
    Post::factory()->create(['slug' => 'open-evening']);

    $this->actingAs($this->admin)->postJson('/api/v1/admin/posts', ['title' => 'Open evening'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'open-evening-2');
});

it('refuses somebody without post.manage, on every endpoint', function (): void {
    $instructor = User::factory()->instructor()->create();
    $post = Post::factory()->create();

    $this->actingAs($instructor)->getJson('/api/v1/admin/posts')->assertForbidden();
    $this->actingAs($instructor)->postJson('/api/v1/admin/posts', ['title' => 'Mine'])->assertForbidden();
    $this->actingAs($instructor)->getJson("/api/v1/admin/posts/{$post->uuid}")->assertForbidden();
    $this->actingAs($instructor)->patchJson("/api/v1/admin/posts/{$post->uuid}", ['title' => 'Renamed'])->assertForbidden();
    $this->actingAs($instructor)->postJson("/api/v1/admin/posts/{$post->uuid}/publish")->assertForbidden();
    $this->actingAs($instructor)->deleteJson("/api/v1/admin/posts/{$post->uuid}")->assertForbidden();

    expect($post->fresh()?->status)->toBe(PostStatus::Draft);
});

it('refuses a post with no title, and a cover that is somebody else\'s file', function (): void {
    expect($this->actingAs($this->admin)->postJson('/api/v1/admin/posts', ['title' => ''])->assertUnprocessable())
        ->toBeApiError('validation_failed');

    $theirs = Media::factory()->create(['collection' => 'course_thumbnail']);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/posts', ['title' => 'Borrowed cover', 'cover_media_id' => $theirs->id])
        ->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('cover_media_id');
});

it('publishes once, and tells the rest of the system only once', function (): void {
    Event::fake([PostPublished::class]);
    $post = Post::factory()->create();

    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.can_edit_slug', false);

    $firstDate = $post->fresh()?->published_at;

    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/publish")->assertOk();

    Event::assertDispatchedTimes(PostPublished::class, 1);

    // Taken down and put back: it keeps the date it first went out.
    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/unpublish")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
    $this->travel(3)->days();
    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/publish")->assertOk();

    expect($post->fresh()?->published_at?->equalTo($firstDate))->toBeTrue();
});

it('schedules a post for later, and announces nothing until it is out', function (): void {
    Event::fake([PostPublished::class]);
    $post = Post::factory()->create();
    $later = now()->addDays(3)->startOfMinute();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/posts/{$post->uuid}/publish", ['published_at' => $later->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.is_scheduled', true);

    Event::assertNotDispatched(PostPublished::class);
});

it('refuses to publish a post with nothing in it', function (): void {
    $post = Post::factory()->create(['body' => '<p> </p>']);

    expect($this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/publish")->assertUnprocessable())
        ->toBeApiError('post_not_publishable');
});

it('lets a draft change its address, and keeps a published one where links point', function (): void {
    $draft = Post::factory()->create(['slug' => 'first-try']);
    $published = Post::factory()->published()->create(['slug' => 'shared-already']);

    $this->actingAs($this->admin)->patchJson("/api/v1/admin/posts/{$draft->uuid}", ['slug' => 'better-address'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'better-address');

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/posts/{$published->uuid}", ['slug' => 'moved'])
        ->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('slug')
        ->and($published->fresh()?->slug)->toBe('shared-already');
});

it('lists every post, drafts included, with filters', function (): void {
    Post::factory()->create(['title' => 'A draft about ink']);
    Post::factory()->published()->create(['title' => 'Out already']);

    $this->actingAs($this->admin)->getJson('/api/v1/admin/posts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.can_manage', true);

    $this->actingAs($this->admin)->getJson('/api/v1/admin/posts?status=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'A draft about ink');

    $this->actingAs($this->admin)->getJson('/api/v1/admin/posts?q=already')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('deletes a post', function (): void {
    $post = Post::factory()->published()->create();

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/posts/{$post->uuid}")->assertNoContent();

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse();
});
