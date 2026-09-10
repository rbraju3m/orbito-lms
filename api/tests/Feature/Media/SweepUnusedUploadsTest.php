<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * `media:sweep-unused` — deletes uploads nothing used within their grace
 * period, reading `UploadQuota`'s own definition of unused. What it must
 * never touch matters more than what it deletes: a handed-in file, a file on
 * an assignment brief, an avatar (until avatars are wired to something), and
 * anything an author uploaded.
 */

beforeEach(function (): void {
    seedRegistry();
    Storage::fake('public');
    Storage::fake('private');
});

function sweepUpload(User $user, string $collection = 'submission', string $name = 'notes.pdf'): TestResponse
{
    return test()->actingAs($user)->postJson('/api/v1/media', [
        'collection' => $collection,
        'file' => $collection === 'avatar'
            ? UploadedFile::fake()->image('me.jpg', 200, 200)
            : UploadedFile::fake()->create($name, 40, 'application/pdf'),
    ]);
}

/** Past the default 48-hour grace period. */
function sweepAfterGrace(): void
{
    test()->travel(49)->hours();
}

it('deletes an unused submission file once its grace period has passed', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    $upload = sweepUpload($student)->assertCreated();
    $media = Media::firstOrFail();

    sweepAfterGrace();
    $this->artisan('media:sweep-unused')->assertSuccessful();

    // Through DeleteMedia: the bytes go, the row is soft-deleted.
    expect(Media::find($media->id))->toBeNull()
        ->and(Media::withTrashed()->find($media->id))->not->toBeNull();
    Storage::disk('private')->assertMissing($media->path);

    // And the owner has their room back.
    expect($upload->json('data.size_bytes'))->toBe(40 * 1024);
    config(['orbito.media.unattached_quota_bytes' => 40 * 1024]);
    sweepUpload($student)->assertCreated();
});

it('leaves a file still inside its grace period', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();
    sweepUpload($student)->assertCreated();

    $this->travel(47)->hours();
    $this->artisan('media:sweep-unused')->assertSuccessful();

    expect(Media::count())->toBe(1);
});

/* Built the app's way: uploaded, then handed in through the real endpoint. */
it('never deletes a file that was handed in', function (): void {
    $scenario = assignmentScenario();
    $student = $scenario['student'];
    $ref = sweepUpload($student)->assertCreated()->json('data.ref');

    $this->actingAs($student)
        ->postJson("/api/v1/learn/items/{$scenario['item']->uuid}/assignment/submissions", ['media_ids' => [$ref]])
        ->assertCreated();

    sweepAfterGrace();
    $this->artisan('media:sweep-unused')->assertSuccessful();

    expect(Media::count())->toBe(1);
    Storage::disk('private')->assertExists(Media::firstOrFail()->path);
});

it('never deletes a file on an assignment brief', function (): void {
    $scenario = assignmentScenario();

    DB::table('assignment_attachments')->insert([
        'assignment_id' => $scenario['assignment']->id,
        'media_id' => sweepUpload($scenario['instructor'])->assertCreated()->json('data.ref'),
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    sweepAfterGrace();
    $this->artisan('media:sweep-unused')->assertSuccessful();

    expect(Media::count())->toBe(1);
});

/*
 * Nothing references an avatar yet, so every avatar reads as unused and a live
 * one cannot be told from an abandoned one. See
 * `MediaCollection::sweptWhenUnused()`.
 */
it('leaves avatars alone until something uses them', function (): void {
    sweepUpload(User::factory()->withRole(RoleKey::Student)->create(), 'avatar')->assertCreated();

    sweepAfterGrace();
    $this->artisan('media:sweep-unused')->assertSuccessful();

    expect(Media::count())->toBe(1);
});

/* Authoring files are referenced in ways this definition does not know. */
it('leaves authoring collections alone', function (): void {
    sweepUpload(User::factory()->instructor()->create(), 'lesson_attachment')->assertCreated();

    sweepAfterGrace();
    $this->artisan('media:sweep-unused')->assertSuccessful();

    expect(Media::count())->toBe(1);
});

it('reports what it would sweep without deleting anything on a dry run', function (): void {
    sweepUpload(User::factory()->withRole(RoleKey::Student)->create())->assertCreated();

    sweepAfterGrace();
    $this->artisan('media:sweep-unused --dry-run')
        ->expectsOutputToContain('Would sweep 1 unused upload(s), 40 KB')
        ->assertSuccessful();

    expect(Media::count())->toBe(1);
});

it('refuses a grace period under an hour', function (): void {
    $this->artisan('media:sweep-unused --hours=0')->assertFailed();
});

/* The sweep reads the quota's definition; it cannot reach past it. */
it('sweeps only collections the quota counts', function (MediaCollection $collection): void {
    expect(! $collection->sweptWhenUnused() || $collection->hasPersonalQuota())->toBeTrue();
})->with(MediaCollection::cases());
