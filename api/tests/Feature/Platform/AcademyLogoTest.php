<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SwitchesTenants;

/*
 * The academy's logo: a `Media` reference held in the academy's `data` blob,
 * drawn in its public site's header (`Tenant::logoUrl()`).
 *
 * The public read runs with no academy open, as a stranger's does, which
 * purges the connection — so fixtures are committed, not transacted.
 */
uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();
    Storage::fake('public');

    $this->academy = Tenant::findOrFail(tenancy()->tenant->getTenantKey());
    $this->admin = User::factory()->withRole(RoleKey::Admin)->create();
});

/** Uploads a logo the way the settings screen does, and returns its numeric id. */
function uploadLogo(User $as, string $name = 'logo.png'): int
{
    return (int) test()->actingAs($as)->postJson('/api/v1/media', [
        'collection' => 'academy_logo',
        'file' => UploadedFile::fake()->image($name, 240, 80),
    ])->assertCreated()->json('data.ref');
}

it('sets a logo, and the public site draws it', function (): void {
    $ref = uploadLogo($this->admin);

    $body = $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/academy', ['logo_media_id' => $ref])
        ->assertOk()
        ->json('data');

    expect($body['logo_media_id'])->toBe($ref)
        ->and($body['logo_url'])->toBeString();

    tenancy()->end();

    $public = $this->getJson('/api/v1/public/test-academy')->assertOk()->json('data');

    expect($public['logo_url'])->toBe($body['logo_url'])
        // A stranger gets the picture, never the id behind it.
        ->and($public)->not->toHaveKey('logo_media_id');
});

it('has no logo until one is set', function (): void {
    $this->actingAs($this->admin)->getJson('/api/v1/admin/academy')
        ->assertOk()
        ->assertJsonPath('data.logo_media_id', null)
        ->assertJsonPath('data.logo_url', null);
});

it('deletes the old file when the logo is replaced, and when it is taken down', function (): void {
    $first = uploadLogo($this->admin, 'first.png');
    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => $first])->assertOk();
    $firstPath = Media::findOrFail($first)->path;

    $second = uploadLogo($this->admin, 'second.png');
    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => $second])->assertOk();

    expect(Media::find($first))->toBeNull();
    Storage::disk('public')->assertMissing($firstPath);

    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => null])
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    expect(Media::find($second))->toBeNull();
});

it('keeps the logo when other settings are saved', function (): void {
    $ref = uploadLogo($this->admin);
    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => $ref])->assertOk();

    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['support_email' => 'help@example.test'])
        ->assertOk()
        ->assertJsonPath('data.logo_media_id', $ref);

    expect(Media::find($ref))->not->toBeNull();
});

// Teaching in the academy is not speaking for it.
it('refuses somebody without settings.update', function (): void {
    $ref = uploadLogo($this->admin);
    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => $ref])->assertOk();
    $instructor = User::factory()->instructor()->create();

    $this->actingAs($instructor)->patchJson('/api/v1/admin/academy', ['logo_media_id' => null])->assertForbidden();

    expect($this->academy->refresh()->logoMediaId())->toBe($ref)
        ->and(Media::find($ref))->not->toBeNull();
});

// An id that merely exists is not authorized.
it('refuses a file somebody else uploaded, or one that is not a logo', function (): void {
    $colleague = User::factory()->withRole(RoleKey::Admin)->create();
    $theirs = uploadLogo($colleague);
    $thumbnail = Media::factory()->ownedBy($this->admin)->forCollection(MediaCollection::CourseThumbnail)->create();

    foreach ([$theirs, $thumbnail->id, 999_999] as $id) {
        $response = $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/academy', ['logo_media_id' => $id])
            ->assertUnprocessable();

        expect($response->json('error.details.0.field'))->toBe('logo_media_id');
    }

    expect($this->academy->refresh()->logoMediaId())->toBeNull();
});

// Ownership is checked on what is NEW, so a colleague can re-save the form.
it('lets another admin re-send the logo a colleague set', function (): void {
    $ref = uploadLogo($this->admin);
    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => $ref])->assertOk();

    $colleague = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($colleague)
        ->patchJson('/api/v1/admin/academy', ['logo_media_id' => $ref, 'registration_mode' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.logo_media_id', $ref);
});

it('refuses an SVG, which can carry script to every visitor', function (): void {
    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->actingAs($this->admin)
        ->postJson('/api/v1/media', ['collection' => 'academy_logo', 'file' => $svg])
        ->assertUnprocessable();
});

it('reads as no logo once its file is gone', function (): void {
    $ref = uploadLogo($this->admin);
    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => $ref])->assertOk();

    Media::findOrFail($ref)->delete();

    $this->actingAs($this->admin)->getJson('/api/v1/admin/academy')->assertJsonPath('data.logo_url', null);
});

/*
 * The id names a row in THIS academy's `media` table. With another academy
 * open the same number is somebody else's file; drawing it would put one
 * academy's picture in another's header.
 */
it('draws nothing when a different academy is the one open', function (): void {
    $ref = uploadLogo($this->admin);
    $this->actingAs($this->admin)->patchJson('/api/v1/admin/academy', ['logo_media_id' => $ref])->assertOk();

    $academy = $this->academy->refresh();
    expect($academy->logoUrl())->toBeString();

    tenancy()->end();
    expect($academy->logoUrl())->toBeNull();
});
