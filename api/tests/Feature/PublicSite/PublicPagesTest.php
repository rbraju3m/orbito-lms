<?php

declare(strict_types=1);

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Models\Post;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Concerns\SwitchesTenants;

/*
 * The academy's built pages, to a stranger — every request with no user and
 * no academy open (see PublicSiteTest for why). Fixtures first, while the
 * harness still has the academy open; a change made after ending tenancy goes
 * through `Tenant::run()`.
 */

uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();
});

it('serves a published page by its address, with what its blocks point at', function (): void {
    Post::factory()->published()->count(3)->create();

    Page::factory()->published()->withBlocks([
        ['id' => (string) Str::uuid(), 'type' => 'heading', 'props' => ['text' => 'About us', 'level' => 2]],
        ['id' => (string) Str::uuid(), 'type' => 'posts', 'props' => ['title' => 'News', 'limit' => 2]],
    ])->create(['slug' => 'about']);

    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/pages/about')
        ->assertOk()
        ->assertJsonPath('data.blocks.0.props.text', 'About us')
        ->assertJsonCount(2, 'data.blocks.1.data');
});

it('gives a draft page the same 404 as an address that never existed', function (): void {
    Page::factory()->create(['slug' => 'not-ready']);

    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/pages/not-ready')->assertNotFound();
    $this->getJson('/api/v1/public/test-academy/pages/no-such-page')->assertNotFound();
});

it('serves the front page an academy chose — once it is published', function (): void {
    $home = Page::factory()->home()->create(['title' => 'Welcome']);

    tenancy()->end();

    // Chosen but still a draft: the standard front page stays up meanwhile.
    $this->getJson('/api/v1/public/test-academy/home')->assertNotFound();

    Tenant::query()->where('slug', 'test-academy')->firstOrFail()->run(
        fn () => Page::query()->whereKey($home->id)->update([
            'status' => PageStatus::Published->value,
            'published_at' => now(),
        ]),
    );

    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/home')
        ->assertOk()
        ->assertJsonPath('data.title', 'Welcome');
});

it('says there is no front page when the academy has chosen none', function (): void {
    Page::factory()->published()->create();

    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/home')->assertNotFound();
});

it('lists only published pages marked for the header', function (): void {
    Page::factory()->published()->create(['title' => 'About', 'show_in_nav' => true]);
    Page::factory()->published()->create(['title' => 'Hidden', 'show_in_nav' => false]);
    Page::factory()->create(['title' => 'Draft', 'show_in_nav' => true]);

    tenancy()->end();

    $this->getJson('/api/v1/public/test-academy/navigation')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'About');
});

it('tells a stranger nothing that belongs to the people building the site', function (): void {
    Page::factory()->published()->home()->create(['slug' => 'about', 'show_in_nav' => true]);

    tenancy()->end();

    $page = $this->getJson('/api/v1/public/test-academy/pages/about')->assertOk()->json('data');

    foreach (['status', 'status_label', 'published_at', 'is_home', 'show_in_nav', 'can_edit_slug', 'created_at', 'updated_at'] as $key) {
        expect($page)->not->toHaveKey($key);
    }
});
