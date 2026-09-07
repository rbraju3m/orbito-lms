<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->scenario = assignmentScenario();
    $this->item = $this->scenario['item'];
    $this->student = $this->scenario['student'];

    $this->brief = "/api/v1/learn/items/{$this->item->uuid}/assignment";
    $this->submit = "{$this->brief}/submissions";

    $this->fileFor = fn (User $owner, string $name = 'essay.pdf', string $ext = 'pdf', int $bytes = 100_000) => Media::factory()
        ->forCollection(MediaCollection::Submission)
        ->ownedBy($owner)
        ->create(['original_name' => $name, 'extension' => $ext, 'size_bytes' => $bytes]);
});

it('shows the brief, the history and the rules together', function (): void {
    $body = $this->actingAs($this->student)->getJson($this->brief)->assertOk()->json('data');

    expect($body)->toHaveKeys(['assignment', 'submissions', 'rules'])
        ->and($body['submissions'])->toBe([])
        ->and($body['rules'])->toMatchArray([
            'can_submit' => true,
            'reason' => null,
            'attempts_used' => 0,
            'attempts_allowed' => 1,
            'attempts_left' => 1,
            'is_past_due' => false,
            'will_be_late' => false,
        ]);
});

it('takes a written answer', function (): void {
    $body = $this->actingAs($this->student)
        ->postJson($this->submit, ['body' => '<p>My reading of the poem.</p>'])
        ->assertCreated()->json('data');

    expect($body['status'])->toBe('submitted')
        ->and($body['attempt_number'])->toBe(1)
        ->and($body['is_late'])->toBeFalse()
        // Not marked yet is not the same as scoring zero.
        ->and($body)->not->toHaveKey('points_earned');
});

it('takes files the learner uploaded', function (): void {
    $file = ($this->fileFor)($this->student);

    $body = $this->actingAs($this->student)
        ->postJson($this->submit, ['media_ids' => [$file->id]])
        ->assertCreated()->json('data');

    expect($body['files'])->toHaveCount(1)
        ->and($body['files'][0]['name'])->toBe('essay.pdf');
});

it('sanitises the written answer on write', function (): void {
    $this->actingAs($this->student)
        ->postJson($this->submit, ['body' => '<p>Fine</p><script>alert(1)</script>'])
        ->assertCreated();

    $this->assertDatabaseMissing('assignment_submissions', ['body' => null]);
    expect(DB::table('assignment_submissions')->value('body'))
        ->toContain('Fine')
        ->not->toContain('<script>');
});

it('refuses an empty hand-in', function (): void {
    expect($this->actingAs($this->student)->postJson($this->submit, ['body' => '  '])
        ->assertStatus(409))->toBeApiError('submission_rejected');
});

/* An id that merely exists is not authorized. */
it('refuses a file belonging to another learner', function (): void {
    $other = User::factory()->withRole(RoleKey::Student)->create();
    $file = ($this->fileFor)($other);

    $this->actingAs($this->student)
        ->postJson($this->submit, ['media_ids' => [$file->id]])
        ->assertStatus(422);

    $this->assertDatabaseCount('assignment_submissions', 0);
});

it('refuses a file type the assignment does not accept', function (): void {
    $this->scenario['assignment']->update(['allowed_extensions' => ['pdf']]);
    $file = ($this->fileFor)($this->student, 'notes.zip', 'zip');

    $this->actingAs($this->student)
        ->postJson($this->submit, ['media_ids' => [$file->id]])
        ->assertStatus(422)
        ->assertJsonPath('error.details.0.field', 'media_ids');
});

it('refuses a file larger than the assignment allows', function (): void {
    $this->scenario['assignment']->update(['max_file_size_kb' => 100]);
    $file = ($this->fileFor)($this->student, 'huge.pdf', 'pdf', 5_000_000);

    $this->actingAs($this->student)
        ->postJson($this->submit, ['media_ids' => [$file->id]])
        ->assertStatus(422);
});

it('refuses more files than the assignment allows', function (): void {
    $this->scenario['assignment']->update(['max_files' => 1]);

    $this->actingAs($this->student)->postJson($this->submit, [
        'media_ids' => [($this->fileFor)($this->student, 'a.pdf')->id, ($this->fileFor)($this->student, 'b.pdf')->id],
    ])->assertStatus(422);
});

it('refuses files when the assignment takes only writing', function (): void {
    $this->scenario['assignment']->update(['allow_files' => false]);
    $file = ($this->fileFor)($this->student);

    expect($this->actingAs($this->student)
        ->postJson($this->submit, ['media_ids' => [$file->id]])
        ->assertStatus(409))->toBeApiError('submission_rejected');
});

it('refuses writing when the assignment takes only files', function (): void {
    $this->scenario['assignment']->update(['allow_text' => false]);

    $this->actingAs($this->student)
        ->postJson($this->submit, ['body' => 'Words'])
        ->assertStatus(409);
});

it('refuses a second attempt once the cap is reached', function (): void {
    $this->actingAs($this->student)->postJson($this->submit, ['body' => 'First'])->assertCreated();

    expect($this->actingAs($this->student)->postJson($this->submit, ['body' => 'Second'])
        ->assertStatus(409))->toBeApiError('submission_rejected');
});

it('allows another attempt while the cap has room', function (): void {
    $this->scenario['assignment']->update(['max_attempts' => 2]);

    $this->actingAs($this->student)->postJson($this->submit, ['body' => 'First'])->assertCreated();

    expect($this->actingAs($this->student)->postJson($this->submit, ['body' => 'Second'])
        ->assertCreated()->json('data.attempt_number'))->toBe(2);
});

it('allows unlimited attempts when no cap is set', function (): void {
    $this->scenario['assignment']->update(['max_attempts' => null]);

    foreach (range(1, 3) as $n) {
        expect($this->actingAs($this->student)->postJson($this->submit, ['body' => "Try {$n}"])
            ->assertCreated()->json('data.attempt_number'))->toBe($n);
    }

    expect($this->actingAs($this->student)->getJson($this->brief)->json('data.rules'))
        ->toMatchArray(['attempts_allowed' => null, 'attempts_left' => null, 'can_submit' => true]);
});

it('completes the item when work is handed in', function (): void {
    $this->actingAs($this->student)->postJson($this->submit, ['body' => 'Done'])->assertCreated();

    $this->assertDatabaseHas('item_progress', [
        'course_item_id' => $this->item->id,
        'status' => 'completed',
    ]);
});

it('refuses to mark an assignment complete by hand', function (): void {
    expect($this->actingAs($this->student)
        ->postJson("/api/v1/learn/items/{$this->item->uuid}/complete")
        ->assertStatus(409))->toBeApiError('progress_rejected');
});

it('hides another learner submissions', function (): void {
    $this->actingAs($this->student)->postJson($this->submit, ['body' => 'Mine'])->assertCreated();

    $other = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()
        ->create(['course_id' => $this->scenario['course']->id, 'user_id' => $other->id]);

    expect($this->actingAs($other)->getJson($this->brief)->assertOk()->json('data.submissions'))
        ->toBe([]);
});

it('refuses a hand-in from somebody who is not enrolled', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($stranger)->postJson($this->submit, ['body' => 'Hello'])->assertStatus(423);
});

/*
 * Course staff can read the brief but have no enrollment to hand work in
 * against; inventing one would corrupt their own students' statistics.
 */
it('refuses a hand-in from the instructor', function (): void {
    $this->actingAs($this->scenario['instructor'])
        ->postJson($this->submit, ['body' => 'Mine too'])
        ->assertStatus(423);
});

/*
 * The whole round trip through the real upload endpoint, not a factory-made
 * media row: a learner has to be able to reach it with only the Student role.
 */
it('lets an enrolled learner upload a file and hand it in', function (): void {
    Storage::fake('private');

    $uploaded = $this->actingAs($this->student)->postJson('/api/v1/media', [
        'collection' => 'submission',
        'file' => UploadedFile::fake()->create('essay.pdf', 40, 'application/pdf'),
    ])->assertCreated()->json('data');

    $body = $this->actingAs($this->student)
        ->postJson($this->submit, ['media_ids' => [$uploaded['ref']]])
        ->assertCreated()->json('data');

    expect($body['files'])->toHaveCount(1)
        ->and($body['files'][0]['name'])->toBe('essay.pdf')
        // A private file is only ever reachable through a signed URL (ADR-09).
        ->and($body['files'][0]['url'])->toContain('signature=');
});
