<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\RoleAssignment;
use App\Domain\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Who may put bytes into which media collection.
 *
 * `MediaCollection::uploadPermission()` was declared in Phase 4 and never
 * called, so every collection was open to anybody holding `media.upload` — a
 * student could put a 2 GB lesson video on the academy's storage bill. Each
 * collection now names who may write into it, asked with
 * `holdsPermissionAnywhere()`: an upload has no course to ask about yet.
 */

beforeEach(function (): void {
    seedRegistry();
    Storage::fake('public');
    Storage::fake('private');
});

/** A file each collection's allowlist accepts. */
function uploadFor(string $collection): UploadedFile
{
    return match ($collection) {
        'avatar', 'course_thumbnail', 'category_image' => UploadedFile::fake()->image('picture.jpg', 400, 300),
        'lesson_video', 'course_intro_video' => UploadedFile::fake()->create('clip.mp4', 256, 'video/mp4'),
        default => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
    };
}

function uploadAs(User $user, string $collection): TestResponse
{
    return test()->actingAs($user)->postJson('/api/v1/media', [
        'collection' => $collection,
        'file' => uploadFor($collection),
    ]);
}

/* ------------------------------------------------------------- learners */

it('lets a student upload an avatar and a submission', function (string $collection): void {
    uploadAs(User::factory()->withRole(RoleKey::Student)->create(), $collection)->assertCreated();
})->with(['avatar', 'submission']);

/* The hole this closes: authoring collections were open to every uploader. */
it('refuses a student the authoring collections', function (string $collection): void {
    uploadAs(User::factory()->withRole(RoleKey::Student)->create(), $collection)->assertForbidden();
})->with(['course_thumbnail', 'course_intro_video', 'lesson_video', 'lesson_attachment', 'category_image']);

/* Staff hold `media.upload` for support work, and author nothing. */
it('refuses staff a lesson video', function (): void {
    uploadAs(User::factory()->withRole(RoleKey::Staff)->create(), 'lesson_video')->assertForbidden();
});

/* ------------------------------------------------------------- authors */

it('lets an instructor upload into every authoring collection', function (string $collection): void {
    uploadAs(User::factory()->instructor()->create(), $collection)->assertCreated();
})->with(['course_thumbnail', 'course_intro_video', 'lesson_video', 'lesson_attachment']);

/*
 * The reason for `holdsPermissionAnywhere()`. A Course Manager holds
 * `curriculum.manage.own` ONLY on their course, and an upload has no course to
 * ask about yet — asking with no scope would refuse them their own lesson video.
 */
it('lets a Course Manager with only a course-scoped role upload a lesson video', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole(RoleKey::CourseManager, Course::factory()->create());

    uploadAs($manager->fresh(), 'lesson_video')->assertCreated();
});

it('refuses a Course Manager whose course-scoped role has expired', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole(RoleKey::CourseManager, Course::factory()->create());

    RoleAssignment::query()->where('user_id', $manager->id)->update(['expires_at' => now()->subDay()]);

    uploadAs($manager->fresh(), 'lesson_video')->assertForbidden();
});

/* A seat on one course is not the academy's settings. */
it('refuses a Course Manager a category image', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole(RoleKey::CourseManager, Course::factory()->create());

    uploadAs($manager->fresh(), 'category_image')->assertForbidden();
});

/*
 * Admins hold the `.any` permissions, not the `.own` ones — which is why each
 * collection lists several. A single `.own` key would have locked them out.
 */
it('lets an admin upload authoring files and a category image', function (string $collection): void {
    uploadAs(userWithRole(RoleKey::Admin), $collection)->assertCreated();
})->with(['lesson_video', 'course_intro_video', 'lesson_attachment', 'category_image']);

/* ------------------------------------------------- the question itself */

it('answers "held anywhere" for global, course-scoped, expired and absent grants', function (): void {
    $course = Course::factory()->create();

    $global = User::factory()->instructor()->create();

    $scoped = User::factory()->create();
    $scoped->assignRole(RoleKey::CourseManager, $course);

    $expired = User::factory()->create();
    $expired->assignRole(RoleKey::CourseManager, $course);
    RoleAssignment::query()->where('user_id', $expired->id)->update(['expires_at' => now()->subMinute()]);

    $nobody = User::factory()->create();

    expect($global->fresh()->holdsPermissionAnywhere('curriculum.manage.own'))->toBeTrue()
        ->and($scoped->fresh()->holdsPermissionAnywhere('curriculum.manage.own'))->toBeTrue()
        // The scoped holder does NOT pass the narrower, no-scope question —
        // which is exactly why uploads cannot ask that one.
        ->and($scoped->fresh()->hasPermission('curriculum.manage.own'))->toBeFalse()
        ->and($expired->fresh()->holdsPermissionAnywhere('curriculum.manage.own'))->toBeFalse()
        ->and($nobody->fresh()->holdsPermissionAnywhere('curriculum.manage.own'))->toBeFalse()
        // Any one of several is enough.
        ->and($scoped->fresh()->holdsPermissionAnywhere('settings.update', 'curriculum.manage.own'))->toBeTrue();
});
