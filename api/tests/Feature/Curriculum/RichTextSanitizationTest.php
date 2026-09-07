<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->create(),
        [1],
    );
    $this->item = $this->course->items()->first();
});

/*
 * Instructors are semi-trusted, not trusted. A compromised authoring account
 * must not be able to run script in a learner's browser, and sanitising on
 * WRITE means the stored value is safe everywhere it is later used.
 */

it('strips a script tag from lesson content', function (): void {
    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$this->item->uuid}/lesson", [
        'content' => '<p>Safe</p><script>alert(document.cookie)</script>',
    ])->assertOk();

    $stored = (string) $this->item->fresh()->itemable->content;

    expect($stored)->toContain('Safe')
        ->and($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('alert(');
});

it('strips an inline event handler', function (): void {
    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$this->item->uuid}/lesson", [
        'content' => '<p onclick="steal()">Click me</p>',
    ])->assertOk();

    expect((string) $this->item->fresh()->itemable->content)->not->toContain('onclick');
});

it('strips a javascript: link', function (): void {
    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$this->item->uuid}/lesson", [
        'content' => '<a href="javascript:alert(1)">Tap</a>',
    ])->assertOk();

    expect((string) $this->item->fresh()->itemable->content)->not->toContain('javascript:');
});

it('strips an iframe', function (): void {
    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$this->item->uuid}/lesson", [
        'content' => '<iframe src="https://evil.example.com"></iframe><p>After</p>',
    ])->assertOk();

    $stored = (string) $this->item->fresh()->itemable->content;
    expect($stored)->not->toContain('<iframe')->and($stored)->toContain('After');
});

it('keeps the formatting an author actually needs', function (): void {
    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/items/{$this->item->uuid}/lesson", [
        'content' => '<h2>Metre</h2><p><strong>Bold</strong> and <em>italic</em>.</p>'
            .'<ul><li>One</li><li>Two</li></ul><a href="https://example.com">A link</a>',
    ])->assertOk();

    $stored = (string) $this->item->fresh()->itemable->content;

    expect($stored)->toContain('<h2>')
        ->toContain('<strong>')
        ->toContain('<em>')
        ->toContain('<li>')
        ->toContain('https://example.com')
        // Outbound links get rel hardening automatically.
        ->toContain('noopener');
});

it('sanitises a course description too', function (): void {
    $this->actingAs($this->instructor)->patchJson("/api/v1/studio/courses/{$this->course->uuid}", [
        'description' => '<p>Fine</p><script>alert(1)</script>',
    ])->assertOk();

    expect((string) $this->course->fresh()->description)->not->toContain('<script');
});
