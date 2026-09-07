<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Actions\StoreUploadedMedia;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Models\Media;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedRegistry();
    Storage::fake('public');
    Storage::fake('private');
});

it('stores a thumbnail on the public disk', function (): void {
    $user = User::factory()->instructor()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => MediaCollection::CourseThumbnail->value,
        'file' => UploadedFile::fake()->image('cover.jpg', 1280, 720),
    ])->assertCreated();

    expect($response->json('data.is_private'))->toBeFalse()
        ->and($response->json('data.url'))->toBeString();

    $media = Media::firstOrFail();
    Storage::disk('public')->assertExists($media->path);
});

/* Course content must never land somewhere with a permanent public URL. */
it('stores lesson video on the private disk', function (): void {
    $user = User::factory()->instructor()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => MediaCollection::LessonVideo->value,
        'file' => UploadedFile::fake()->create('lesson.mp4', 512, 'video/mp4'),
    ])->assertCreated();

    expect($response->json('data.is_private'))->toBeTrue()
        ->and($response->json('data.url_expires_at'))->not->toBeNull();

    $media = Media::firstOrFail();
    Storage::disk('private')->assertExists($media->path);
    expect($media->publicUrl())->toBeNull();
});

it('rejects a file type the collection does not accept', function (): void {
    $user = User::factory()->instructor()->create();

    expect($this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => MediaCollection::CourseThumbnail->value,
        'file' => UploadedFile::fake()->create('payload.pdf', 10, 'application/pdf'),
    ])->assertStatus(422))->toBeApiError('media_rejected');

    expect(Media::count())->toBe(0);
});

/*
 * The client's declared MIME type is attacker-controlled; the stored type is
 * derived from the file's own bytes.
 *
 * This uses a REAL temp file rather than UploadedFile::fake(): Laravel's fake
 * reports a MIME derived from the filename, so it would sail past the very
 * check under test and the assertion would prove nothing.
 */
it('does not trust a spoofed content type', function (): void {
    $user = User::factory()->instructor()->create();

    $path = tempnam(sys_get_temp_dir(), 'orbito-spoof');
    file_put_contents($path, '<?php echo "pwned"; ?>');

    // A PHP script announcing itself as a JPEG.
    $file = new UploadedFile($path, 'shell.jpg', 'image/jpeg', null, true);

    $store = app(StoreUploadedMedia::class);

    expect(fn () => $store->handle($user, $file, MediaCollection::CourseThumbnail))
        ->toThrow(MediaRejected::class, 'text/x-php');

    expect(Media::count())->toBe(0);

    @unlink($path);
});

it('never keeps the uploaded filename or extension on disk', function (): void {
    $user = User::factory()->instructor()->create();

    $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => MediaCollection::CourseThumbnail->value,
        'file' => UploadedFile::fake()->image('../../evil.php.jpg'),
    ])->assertCreated();

    $media = Media::firstOrFail();

    expect($media->path)->not->toContain('..')
        ->and($media->path)->not->toContain('evil')
        ->and($media->extension)->toBe('jpg')
        ->and($media->path)->toEndWith('.jpg');
});

it('rejects a file over the collection size cap', function (): void {
    $user = User::factory()->instructor()->create();

    // Avatars cap at 2 MB.
    $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => MediaCollection::Avatar->value,
        'file' => UploadedFile::fake()->image('huge.jpg')->size(4096),
    ])->assertStatus(422);
});

it('requires authentication to upload', function (): void {
    $this->postJson('/api/v1/media', [
        'collection' => MediaCollection::CourseThumbnail->value,
        'file' => UploadedFile::fake()->image('cover.jpg'),
    ])->assertStatus(401);
});

it('tracks storage usage per owner and platform-wide', function (): void {
    $counters = app(UsageCounters::class);
    $user = User::factory()->instructor()->create();

    $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => MediaCollection::CourseThumbnail->value,
        'file' => UploadedFile::fake()->image('cover.jpg'),
    ])->assertCreated();

    $media = Media::firstOrFail();

    expect($counters->get(UsageMetric::MediaFiles, $user))->toBe(1)
        ->and($counters->get(UsageMetric::StorageBytes, $user))->toBe($media->size_bytes)
        ->and($counters->get(UsageMetric::StorageBytes))->toBe($media->size_bytes);

    $this->actingAs($user)->deleteJson("/api/v1/media/{$media->uuid}")->assertNoContent();

    expect($counters->get(UsageMetric::MediaFiles, $user))->toBe(0)
        ->and($counters->get(UsageMetric::StorageBytes, $user))->toBe(0);
});

it('removes the bytes from disk when media is deleted', function (): void {
    $user = User::factory()->instructor()->create();

    $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => MediaCollection::CourseThumbnail->value,
        'file' => UploadedFile::fake()->image('cover.jpg'),
    ])->assertCreated();

    $media = Media::firstOrFail();
    $path = $media->path;

    $this->actingAs($user)->deleteJson("/api/v1/media/{$media->uuid}")->assertNoContent();

    // A soft-deleted row pointing at a file we still pay to store is the worst
    // of both worlds.
    Storage::disk('public')->assertMissing($path);
});

it('does not let one user delete another user media', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();
    $media = Media::factory()->ownedBy($owner)->create();

    expect($this->actingAs($stranger)->deleteJson("/api/v1/media/{$media->uuid}")->assertStatus(403))
        ->toBeApiError('forbidden');
});

it('lets an admin delete any media', function (): void {
    $owner = User::factory()->instructor()->create();
    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    $media = Media::factory()->ownedBy($owner)->create();

    $this->actingAs($admin)->deleteJson("/api/v1/media/{$media->uuid}")->assertNoContent();
});

it('does not let one user mint a signed url for another private file', function (): void {
    $owner = User::factory()->instructor()->create();
    $stranger = User::factory()->instructor()->create();

    $media = Media::factory()
        ->ownedBy($owner)
        ->forCollection(MediaCollection::LessonVideo)
        ->create();

    $this->actingAs($stranger)->getJson("/api/v1/media/{$media->uuid}/url")->assertStatus(403);
});

it('refuses a private download without a valid signature', function (): void {
    $owner = User::factory()->instructor()->create();
    $media = Media::factory()->ownedBy($owner)->forCollection(MediaCollection::LessonVideo)->create();

    // The signature IS the credential; being signed in is not enough.
    $this->actingAs($owner)
        ->get("/api/v1/media/{$media->uuid}/download")
        ->assertStatus(403);
});

/*
 * The upload response has to be usable: every endpoint that references a file
 * speaks in the numeric id, so without this an upload cannot be attached to
 * anything.
 */
it('hands back both identifiers for the uploaded file', function (): void {
    $user = User::factory()->instructor()->create();

    $body = $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => 'submission',
        'file' => UploadedFile::fake()->create('essay.pdf', 40, 'application/pdf'),
    ])->assertCreated()->json('data');

    expect($body['id'])->toBeString()
        ->and($body['ref'])->toBeInt()
        ->and($body['ref'])->toBe(Media::first()->id);
});
