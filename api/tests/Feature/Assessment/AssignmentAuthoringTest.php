<?php

declare(strict_types=1);

use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;

beforeEach(function (): void {
    $this->scenario = assignmentScenario();
    $this->url = "/api/v1/studio/items/{$this->scenario['item']->uuid}/assignment";
});

it('adds an assignment to the curriculum', function (): void {
    $section = CourseSection::factory()->create(['course_id' => $this->scenario['course']->id]);

    $response = $this->actingAs($this->scenario['instructor'])
        ->postJson("/api/v1/studio/courses/{$this->scenario['course']->uuid}/items", [
            'section_id' => $section->id,
            'type' => 'assignment',
            'title' => 'Essay two',
        ])->assertCreated();

    expect($response->json('data.type'))->toBe('assignment');

    $this->assertDatabaseHas('course_items', [
        'title' => 'Essay two',
        'type' => ItemType::Assignment->value,
    ]);
});

it('returns the brief and its settings', function (): void {
    $body = $this->actingAs($this->scenario['instructor'])->getJson($this->url)
        ->assertOk()->json('data');

    expect($body)->toHaveKeys([
        'instructions', 'total_points', 'due_at', 'late_policy',
        'max_attempts', 'allow_text', 'allow_files', 'max_files', 'allowed_extensions',
    ])->and($body['late_policy'])->toBe('accept');
});

it('saves the settings an author changed', function (): void {
    $due = now()->addWeek()->startOfSecond();

    $body = $this->actingAs($this->scenario['instructor'])->patchJson($this->url, [
        'instructions' => '<p>Two thousand words.</p>',
        'total_points' => 40,
        'passing_points' => 20,
        'due_at' => $due->toIso8601String(),
        'late_policy' => 'penalise',
        'late_penalty_percent' => 25,
        'max_attempts' => 3,
        'allowed_extensions' => ['PDF', '.docx'],
    ])->assertOk()->json('data');

    expect((float) $body['total_points'])->toBe(40.0)
        ->and($body['late_policy'])->toBe('penalise')
        ->and($body['max_attempts'])->toBe(3)
        // Normalised on the way out: an author types ".PDF", a learner's file
        // reports "pdf", and the comparison has to work.
        ->and($body['allowed_extensions'])->toBe(['pdf', 'docx']);
});

it('sanitises the instructions on write', function (): void {
    $this->actingAs($this->scenario['instructor'])->patchJson($this->url, [
        'instructions' => '<p>Read this</p><script>alert(1)</script>',
    ])->assertOk();

    expect($this->scenario['assignment']->refresh()->instructions)
        ->toContain('Read this')
        ->not->toContain('<script>');
});

it('refuses an assignment that can neither be written nor uploaded', function (): void {
    $this->actingAs($this->scenario['instructor'])->patchJson($this->url, [
        'allow_text' => false,
        'allow_files' => false,
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a penalty policy with no penalty', function (): void {
    $this->actingAs($this->scenario['instructor'])->patchJson($this->url, [
        'late_policy' => 'penalise',
        'late_penalty_percent' => 0,
    ])->assertStatus(422);
});

it('attaches a brief the author owns', function (): void {
    $media = Media::factory()
        ->forCollection(MediaCollection::LessonAttachment)
        ->ownedBy($this->scenario['instructor'])
        ->create(['original_name' => 'brief.pdf', 'extension' => 'pdf']);

    $body = $this->actingAs($this->scenario['instructor'])->patchJson($this->url, [
        'attachment_media_ids' => [$media->id],
    ])->assertOk()->json('data');

    expect($body['attachments'])->toHaveCount(1)
        ->and($body['attachments'][0]['name'])->toBe('brief.pdf');
});

/* An id that merely exists is not authorized (the Phase 4 rule). */
it('refuses to attach a file belonging to somebody else', function (): void {
    $media = Media::factory()->forCollection(MediaCollection::LessonAttachment)->create();

    $this->actingAs($this->scenario['instructor'])->patchJson($this->url, [
        'attachment_media_ids' => [$media->id],
    ])->assertStatus(422);

    expect($this->scenario['assignment']->refresh()->attachments)->toHaveCount(0);
});

it('does not let a learner reach the authoring view', function (): void {
    expect($this->actingAs($this->scenario['student'])->getJson($this->url)->assertStatus(403))
        ->toBeApiError('forbidden');
});

it('does not let an unrelated instructor edit another course assignment', function (): void {
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)->patchJson($this->url, ['total_points' => 1])->assertStatus(403);
});

it('404s when the item is not an assignment at all', function (): void {
    $quiz = quizScenario();

    $this->actingAs($quiz['instructor'])
        ->getJson("/api/v1/studio/items/{$quiz['item']->uuid}/assignment")
        ->assertNotFound();
});
