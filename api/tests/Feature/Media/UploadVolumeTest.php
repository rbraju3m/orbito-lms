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
 * Upload VOLUME — the half upload permissions left open. A learner could
 * upload 25 MB submissions forever and never hand one in. Now: a rate limit
 * on the endpoint, and a quota on what a person holds in files they uploaded
 * and have not used (`UploadQuota`).
 */

beforeEach(function (): void {
    seedRegistry();
    Storage::fake('public');
    Storage::fake('private');
});

/** Small enough to reach: two 40 KB files fit, a third does not. */
function volumeTightQuota(): void
{
    config(['orbito.media.unattached_quota_bytes' => 100 * 1024]);
}

function volumeUpload(User $user, string $name = 'notes.pdf', int $kilobytes = 40): TestResponse
{
    return test()->actingAs($user)->postJson('/api/v1/media', [
        'collection' => 'submission',
        'file' => UploadedFile::fake()->create($name, $kilobytes, 'application/pdf'),
    ]);
}

/* -------------------------------------------------------------- the quota */

it('refuses an upload that would take a learner past the quota', function (): void {
    volumeTightQuota();
    $student = User::factory()->withRole(RoleKey::Student)->create();

    volumeUpload($student, 'a.pdf')->assertCreated();
    volumeUpload($student, 'b.pdf')->assertCreated();

    $response = volumeUpload($student, 'c.pdf')->assertStatus(409);

    // `meta` is what they can act on: how full, and how big the file was.
    expect($response)->toBeApiError('upload_quota_exceeded')
        ->and($response->json('error.meta'))->toBe([
            'used_bytes' => 80 * 1024,
            'limit_bytes' => 100 * 1024,
            'file_bytes' => 40 * 1024,
        ]);

    // Refused BEFORE the bytes were written, not cleaned up after.
    expect(Media::count())->toBe(2)
        ->and(Storage::disk('private')->allFiles())->toHaveCount(2);
});

it('counts avatars and submissions against one quota', function (): void {
    volumeTightQuota();
    $student = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($student)->postJson('/api/v1/media', [
        'collection' => 'avatar',
        'file' => UploadedFile::fake()->image('me.jpg', 200, 200)->size(70),
    ])->assertCreated();

    volumeUpload($student)->assertStatus(409);
});

/* The point of counting UNUSED files: handing work in never counts against you. */
it('stops counting a file once it is handed in', function (): void {
    $scenario = assignmentScenario();
    volumeTightQuota();
    $student = $scenario['student'];

    $refs = [
        volumeUpload($student, 'a.pdf')->assertCreated()->json('data.ref'),
        volumeUpload($student, 'b.pdf')->assertCreated()->json('data.ref'),
    ];
    volumeUpload($student, 'c.pdf')->assertStatus(409);

    $this->actingAs($student)
        ->postJson("/api/v1/learn/items/{$scenario['item']->uuid}/assignment/submissions", ['media_ids' => $refs])
        ->assertCreated();

    volumeUpload($student, 'c.pdf')->assertCreated();
    volumeUpload($student, 'd.pdf')->assertCreated();
});

/* `UpdateAssignmentRequest` accepts submission files as brief attachments. */
it('does not count a file attached to an assignment brief', function (): void {
    $scenario = assignmentScenario();
    volumeTightQuota();
    $instructor = $scenario['instructor'];

    foreach (['a.pdf', 'b.pdf'] as $position => $name) {
        DB::table('assignment_attachments')->insert([
            'assignment_id' => $scenario['assignment']->id,
            'media_id' => volumeUpload($instructor, $name)->assertCreated()->json('data.ref'),
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    volumeUpload($instructor, 'c.pdf')->assertCreated();
});

it('gives the room back when an unused file is deleted', function (): void {
    volumeTightQuota();
    $student = User::factory()->withRole(RoleKey::Student)->create();

    $first = volumeUpload($student, 'a.pdf')->assertCreated()->json('data.id');
    volumeUpload($student, 'b.pdf')->assertCreated();
    volumeUpload($student, 'c.pdf')->assertStatus(409);

    $this->actingAs($student)->deleteJson("/api/v1/media/{$first}")->assertNoContent();

    volumeUpload($student, 'c.pdf')->assertCreated();
});

it('charges nobody for somebody else\'s uploads', function (): void {
    volumeTightQuota();
    $full = User::factory()->withRole(RoleKey::Student)->create();
    $other = User::factory()->withRole(RoleKey::Student)->create();

    volumeUpload($full, 'a.pdf')->assertCreated();
    volumeUpload($full, 'b.pdf')->assertCreated();
    volumeUpload($full, 'c.pdf')->assertStatus(409);

    volumeUpload($other, 'a.pdf')->assertCreated();
});

/* Authoring is the academy's storage — the plan's figure, not one person's. */
it('does not apply the quota to authoring collections', function (): void {
    volumeTightQuota();
    $instructor = User::factory()->instructor()->create();

    foreach (['a', 'b', 'c'] as $name) {
        $this->actingAs($instructor)->postJson('/api/v1/media', [
            'collection' => 'lesson_attachment',
            'file' => UploadedFile::fake()->create("{$name}.pdf", 40, 'application/pdf'),
        ])->assertCreated();
    }
});

/*
 * A default below one full submission would stop a learner assembling one.
 * `UpdateAssignmentRequest` allows 20 files of up to 25 600 KB.
 */
it('defaults to a quota that fits the largest submission an assignment can ask for', function (): void {
    expect(config('orbito.media.unattached_quota_bytes'))->toBeGreaterThanOrEqual(20 * 25_600 * 1024);
});

/* One decision in two places: open to everybody means counted per person. */
it('quotas exactly the collections anybody may upload into', function (MediaCollection $collection): void {
    expect($collection->hasPersonalQuota())->toBe($collection->uploadPermissions() === ['media.upload']);
})->with(MediaCollection::cases());

/* --------------------------------------------------------- the rate limit */

it('rate-limits uploads per person', function (): void {
    config(['orbito.rate_limits.uploads' => 2]);
    $student = User::factory()->withRole(RoleKey::Student)->create();
    $other = User::factory()->withRole(RoleKey::Student)->create();

    volumeUpload($student, 'a.pdf', 1)->assertCreated();
    volumeUpload($student, 'b.pdf', 1)->assertCreated();

    expect(volumeUpload($student, 'c.pdf', 1)->assertStatus(429)->assertHeader('Retry-After'))
        ->toBeApiError('rate_limited');

    // Somebody else's budget is their own.
    volumeUpload($other, 'd.pdf', 1)->assertCreated();
});

/* ------------------------------------------------- handed in stays handed in */

it('refuses to delete a file that has been handed in', function (): void {
    $scenario = assignmentScenario();
    $student = $scenario['student'];
    $upload = volumeUpload($student)->assertCreated();

    $this->actingAs($student)
        ->postJson("/api/v1/learn/items/{$scenario['item']->uuid}/assignment/submissions", [
            'media_ids' => [$upload->json('data.ref')],
        ])
        ->assertCreated();

    expect($this->actingAs($student)->deleteJson('/api/v1/media/'.$upload->json('data.id'))->assertStatus(409))
        ->toBeApiError('media_in_use');

    Storage::disk('private')->assertExists(Media::firstOrFail()->path);
    expect(Media::count())->toBe(1);
});
