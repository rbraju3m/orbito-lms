<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseStatus;

it('allows a draft to be submitted, published or archived', function (): void {
    $draft = CourseStatus::Draft;

    expect($draft->canTransitionTo(CourseStatus::InReview))->toBeTrue()
        ->and($draft->canTransitionTo(CourseStatus::Published))->toBeTrue()
        ->and($draft->canTransitionTo(CourseStatus::Archived))->toBeTrue();
});

it('lets a submitted course be approved or sent back', function (): void {
    expect(CourseStatus::InReview->canTransitionTo(CourseStatus::Published))->toBeTrue()
        ->and(CourseStatus::InReview->canTransitionTo(CourseStatus::Draft))->toBeTrue();
});

it('lets a published course be unpublished or archived', function (): void {
    expect(CourseStatus::Published->canTransitionTo(CourseStatus::Draft))->toBeTrue()
        ->and(CourseStatus::Published->canTransitionTo(CourseStatus::Archived))->toBeTrue();
});

/* Archiving is not deletion — an archived course can come back. */
it('lets an archived course be restored', function (): void {
    expect(CourseStatus::Archived->canTransitionTo(CourseStatus::Draft))->toBeTrue()
        ->and(CourseStatus::Archived->canTransitionTo(CourseStatus::Published))->toBeTrue();
});

it('refuses to send a published course straight back to review', function (): void {
    expect(CourseStatus::Published->canTransitionTo(CourseStatus::InReview))->toBeFalse();
});

it('refuses to move an archived course into review', function (): void {
    expect(CourseStatus::Archived->canTransitionTo(CourseStatus::InReview))->toBeFalse();
});

it('reports which statuses are live and editable', function (): void {
    expect(CourseStatus::Published->isLive())->toBeTrue()
        ->and(CourseStatus::Draft->isLive())->toBeFalse()
        ->and(CourseStatus::Archived->isLive())->toBeFalse()
        ->and(CourseStatus::Draft->isEditable())->toBeTrue()
        ->and(CourseStatus::InReview->isEditable())->toBeTrue()
        ->and(CourseStatus::Published->isEditable())->toBeFalse();
});
