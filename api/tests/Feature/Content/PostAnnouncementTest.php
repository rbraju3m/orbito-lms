<?php

declare(strict_types=1);

use App\Domain\Content\Events\PostPublished;
use App\Domain\Content\Models\Post;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Webhook\Enums\WebhookTopic;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\HostResolver;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

/*
 * `post.published` for a SCHEDULED post (docs/BLOG.md §4). The post appears on
 * the public site by the clock; `blog:announce` is what tells anybody, once
 * per post, ever (`AnnouncePost`).
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
});

function schedulePost(object $test, Post $post, CarbonInterface $at): void
{
    $test->actingAs($test->admin)
        ->postJson("/api/v1/admin/posts/{$post->uuid}/publish", ['published_at' => $at->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.is_scheduled', true);
}

it('announces a scheduled post when its time comes, and not before', function (): void {
    Event::fake([PostPublished::class]);
    $post = Post::factory()->create();
    schedulePost($this, $post, now()->addHours(2)->startOfMinute());

    $this->artisan('blog:announce')->assertSuccessful();
    Event::assertNotDispatched(PostPublished::class);

    $this->travel(2)->hours();
    $this->travel(1)->minutes();
    $this->artisan('blog:announce')->assertSuccessful();

    Event::assertDispatchedTimes(PostPublished::class, 1);
    Event::assertDispatched(PostPublished::class, fn (PostPublished $e): bool => $e->post->is($post));
    expect($post->fresh()?->announced_at)->not->toBeNull();
});

it('announces once, however often the sweep runs', function (): void {
    Event::fake([PostPublished::class]);
    $post = Post::factory()->create();
    schedulePost($this, $post, now()->addHour()->startOfMinute());
    $this->travel(2)->hours();

    $this->artisan('blog:announce')->assertSuccessful();
    $this->artisan('blog:announce')->assertSuccessful();

    Event::assertDispatchedTimes(PostPublished::class, 1);
});

it('never announces a post again once it has been out', function (): void {
    Event::fake([PostPublished::class]);
    $post = Post::factory()->create();

    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/publish")->assertOk();
    // Taken down to fix a typo, and put back. Not news.
    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/unpublish")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/publish")->assertOk();
    $this->artisan('blog:announce')->assertSuccessful();

    Event::assertDispatchedTimes(PostPublished::class, 1);
});

it('announces at once when a scheduled post is brought forward to now', function (): void {
    Event::fake([PostPublished::class]);
    $post = Post::factory()->create();
    schedulePost($this, $post, now()->addDays(3)->startOfMinute());

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/posts/{$post->uuid}/publish", ['published_at' => now()->subMinute()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.is_scheduled', false);

    Event::assertDispatchedTimes(PostPublished::class, 1);
});

it('does not announce a scheduled post taken back to draft before its time', function (): void {
    Event::fake([PostPublished::class]);
    $post = Post::factory()->create();
    schedulePost($this, $post, now()->addHour()->startOfMinute());

    $this->actingAs($this->admin)->postJson("/api/v1/admin/posts/{$post->uuid}/unpublish")->assertOk();
    $this->travel(2)->hours();
    $this->artisan('blog:announce')->assertSuccessful();

    Event::assertNotDispatched(PostPublished::class);
});

it('does not re-announce posts that were already out', function (): void {
    Event::fake([PostPublished::class]);
    Post::factory()->published()->count(2)->create();

    $this->artisan('blog:announce')->assertSuccessful();

    Event::assertNotDispatched(PostPublished::class);
});

// The point of the slice: an integration hears about a scheduled post.
it('sends post.published to an integration when a scheduled post goes out', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    app()->instance(HostResolver::class, new FakeHostResolver);
    WebhookEndpoint::factory()->listeningTo([WebhookTopic::PostPublished])->create();

    $post = Post::factory()->create(['title' => 'Open evening']);
    schedulePost($this, $post, now()->addHour()->startOfMinute());
    Http::assertNothingSent();

    $this->travel(2)->hours();
    $this->artisan('blog:announce')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"post.published"')
        && str_contains($request->body(), 'Open evening'));
});
