<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Models\Course;
use App\Domain\Content\Models\Page;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Building the academy's pages (`page.manage` — Admin and Super Admin). The
 * block list is saved WHOLE, from a closed set of types, and whatever a block
 * points at is resolved with the public scopes when the page is read.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
});

/** @param  list<array<string, mixed>>  $blocks */
function savePageBlocks(Page $page, array $blocks): TestResponse
{
    return test()->actingAs(test()->admin)->putJson("/api/v1/admin/pages/{$page->uuid}/blocks", ['blocks' => $blocks]);
}

/** @param  array<string, mixed>  $props */
function pageBlock(string $type, array $props): array
{
    return ['id' => (string) Str::uuid(), 'type' => $type, 'props' => $props];
}

it('starts a draft page with no blocks and an address made from its title', function (): void {
    $this->actingAs($this->admin)->postJson('/api/v1/admin/pages', ['title' => 'About the school'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'about-the-school')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.blocks', [])
        ->assertJsonPath('data.is_home', false);
});

it('saves the whole list, keeping only what each block declares and cleaning its HTML', function (): void {
    $page = Page::factory()->create();

    savePageBlocks($page, [
        pageBlock('heading', ['text' => 'Welcome', 'level' => 2, 'onclick' => 'alert(1)']),
        pageBlock('text', ['html' => '<p>Paint every day.</p><script>alert(1)</script>']),
    ])->assertOk()->assertJsonCount(2, 'data.blocks');

    $stored = $page->fresh()?->blockList() ?? [];

    expect($stored)->toHaveCount(2)
        // A prop the type does not declare is never stored.
        ->and($stored[0]['props'])->toBe(['text' => 'Welcome', 'level' => 2])
        ->and($stored[1]['props']['html'])->toContain('<p>Paint every day.</p>')
        ->and($stored[1]['props']['html'])->not->toContain('script');
});

it('refuses a block type it does not know', function (): void {
    $page = Page::factory()->create();

    $response = savePageBlocks($page, [pageBlock('iframe', ['src' => 'https://evil.example'])])->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('blocks.0.type');
});

it('refuses a button that runs script, and one that leaves the site by a path-looking link', function (): void {
    $page = Page::factory()->create();

    foreach (['javascript:alert(1)', '//evil.example/login', 'data:text/html,hi'] as $url) {
        $response = savePageBlocks($page, [pageBlock('button', ['label' => 'Go', 'url' => $url])])->assertUnprocessable();

        expect($response->json('error.details.0.field'))->toBe('blocks.0.props.url');
    }

    savePageBlocks($page, [
        pageBlock('button', ['label' => 'Courses', 'url' => '/a/test-academy']),
        pageBlock('button', ['label' => 'Map', 'url' => 'https://maps.example/school']),
    ])->assertOk();
});

it('refuses somebody else\'s image, and keeps one a colleague already placed', function (): void {
    $theirs = Media::factory()->create(['collection' => 'course_thumbnail']);
    $page = Page::factory()->create();

    $response = savePageBlocks($page, [pageBlock('image', ['media_ref' => $theirs->id, 'alt' => 'Studio'])])
        ->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('blocks.0.props.media_ref');

    // Already on the page — checked when the colleague added it. Re-saving
    // the page must not refuse a picture that is not the saver's.
    $placed = Page::factory()->withBlocks([pageBlock('image', ['media_ref' => $theirs->id, 'alt' => 'Studio'])])->create();

    savePageBlocks($placed, [
        ...$placed->blockList(),
        pageBlock('heading', ['text' => 'Our studio', 'level' => 2]),
    ])->assertOk();
});

it('refuses a page of more than fifty blocks', function (): void {
    $page = Page::factory()->create();

    $blocks = array_map(fn (int $i): array => pageBlock('heading', ['text' => "Block {$i}", 'level' => 2]), range(1, 51));

    savePageBlocks($page, $blocks)->assertUnprocessable();
});

it('shows a course block only the courses a stranger could open', function (): void {
    $live = Course::factory()->published()->create(['title' => 'Watercolour', 'visibility' => CourseVisibility::Public]);
    $draft = Course::factory()->create(['title' => 'Not finished']);
    $page = Page::factory()->create();

    savePageBlocks($page, [pageBlock('courses', ['course_ids' => [$draft->uuid, $live->uuid]])])
        ->assertOk()
        ->assertJsonCount(1, 'data.blocks.0.data')
        ->assertJsonPath('data.blocks.0.data.0.title', 'Watercolour');
});

it('keeps one front page at a time', function (): void {
    $first = Page::factory()->create();
    $second = Page::factory()->create();

    $this->actingAs($this->admin)->postJson("/api/v1/admin/pages/{$first->uuid}/home")
        ->assertOk()
        ->assertJsonPath('data.is_home', true);

    $this->actingAs($this->admin)->postJson("/api/v1/admin/pages/{$second->uuid}/home")
        ->assertOk()
        ->assertJsonPath('data.is_home', true);

    expect($first->fresh()?->isHome())->toBeFalse()
        ->and(Page::query()->where('home_key', Page::HOME)->count())->toBe(1);

    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/pages/{$second->uuid}/home")
        ->assertOk()
        ->assertJsonPath('data.is_home', false);
});

it('refuses to publish a page with no blocks', function (): void {
    $page = Page::factory()->withBlocks([])->create();

    expect($this->actingAs($this->admin)->postJson("/api/v1/admin/pages/{$page->uuid}/publish")->assertUnprocessable())
        ->toBeApiError('page_not_publishable');
});

it('locks the address of a page once it has been published', function (): void {
    $page = Page::factory()->create(['slug' => 'about']);

    $this->actingAs($this->admin)->postJson("/api/v1/admin/pages/{$page->uuid}/publish")->assertOk();

    $response = $this->actingAs($this->admin)->patchJson("/api/v1/admin/pages/{$page->uuid}", ['slug' => 'about-us'])
        ->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('slug');
});

it('refuses somebody without page.manage, on every endpoint', function (): void {
    $instructor = User::factory()->instructor()->create();
    $page = Page::factory()->create();

    $this->actingAs($instructor)->getJson('/api/v1/admin/pages')->assertForbidden();
    $this->actingAs($instructor)->postJson('/api/v1/admin/pages', ['title' => 'Mine'])->assertForbidden();
    $this->actingAs($instructor)->getJson("/api/v1/admin/pages/{$page->uuid}")->assertForbidden();
    $this->actingAs($instructor)->patchJson("/api/v1/admin/pages/{$page->uuid}", ['title' => 'Renamed'])->assertForbidden();
    $this->actingAs($instructor)
        ->putJson("/api/v1/admin/pages/{$page->uuid}/blocks", ['blocks' => [pageBlock('heading', ['text' => 'Hi', 'level' => 2])]])
        ->assertForbidden();
    $this->actingAs($instructor)->postJson("/api/v1/admin/pages/{$page->uuid}/publish")->assertForbidden();
    $this->actingAs($instructor)->postJson("/api/v1/admin/pages/{$page->uuid}/home")->assertForbidden();
    $this->actingAs($instructor)->deleteJson("/api/v1/admin/pages/{$page->uuid}")->assertForbidden();

    expect($page->fresh()?->isHome())->toBeFalse();
});
