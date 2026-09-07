<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedRegistry();
});

it('grants a global role its permissions everywhere', function (): void {
    $instructor = User::factory()->withRole(RoleKey::Instructor)->create();

    expect($instructor->hasPermission('course.create'))->toBeTrue()
        ->and($instructor->hasPermission('curriculum.manage.own'))->toBeTrue();
});

it('denies permissions the role does not hold', function (): void {
    $instructor = User::factory()->withRole(RoleKey::Instructor)->create();

    expect($instructor->hasPermission('user.delete'))->toBeFalse()
        ->and($instructor->hasPermission('order.refund'))->toBeFalse()
        ->and($instructor->hasPermission('course.update.any'))->toBeFalse();
});

it('gives every registered user the student role', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    expect($user->hasRole(RoleKey::Student))->toBeTrue()
        ->and($user->hasPermission('review.create'))->toBeTrue()
        ->and($user->hasPermission('course.create'))->toBeFalse();
});

/*
 * The point of ADR-07: a course-scoped role answers true for its own course and
 * false for every other one. Courses do not exist until Phase 4, so the scope is
 * exercised through another model — which is exactly the polymorphism we want.
 */
it('applies a scoped role only to the resource it was granted on', function (): void {
    $scopeA = User::factory()->create();
    $scopeB = User::factory()->create();

    $ta = User::factory()->withRole(RoleKey::Student)->create();
    $ta->assignRole(RoleKey::TeachingAssistant, $scopeA);

    expect($ta->hasPermission('quiz.grade.own', $scopeA))->toBeTrue()
        ->and($ta->hasPermission('quiz.grade.own', $scopeB))->toBeFalse()
        ->and($ta->hasPermission('quiz.grade.own'))->toBeFalse();
});

it('unions global and scoped permissions when a scope is given', function (): void {
    $scope = User::factory()->create();

    $user = User::factory()->withRole(RoleKey::Student)->create();
    $user->assignRole(RoleKey::TeachingAssistant, $scope);

    // review.create is global (student); quiz.grade.own is scoped (TA).
    expect($user->hasPermission('review.create', $scope))->toBeTrue()
        ->and($user->hasPermission('quiz.grade.own', $scope))->toBeTrue();
});

it('refuses to scope a global role', function (): void {
    $user = User::factory()->create();
    $scope = User::factory()->create();

    expect(fn () => $user->assignRole(RoleKey::Admin, $scope))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to assign a course-scoped role without a scope', function (): void {
    $user = User::factory()->create();

    expect(fn () => $user->assignRole(RoleKey::TeachingAssistant))
        ->toThrow(InvalidArgumentException::class);
});

it('ignores an expired assignment', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();
    $assignment = $user->assignRole(RoleKey::Instructor);
    $assignment->update(['expires_at' => now()->subMinute()]);

    $user->forgetPermissionCache();

    expect($user->hasPermission('course.create'))->toBeFalse()
        ->and($user->hasRole(RoleKey::Instructor))->toBeFalse();
});

it('honours an assignment that has not expired yet', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();
    $assignment = $user->assignRole(RoleKey::Instructor);
    $assignment->update(['expires_at' => now()->addDay()]);

    $user->forgetPermissionCache();

    expect($user->hasPermission('course.create'))->toBeTrue();
});

it('does not duplicate an assignment granted twice', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();
    $user->assignRole(RoleKey::Instructor);
    $user->assignRole(RoleKey::Instructor);

    expect($user->roleAssignments()->count())->toBe(2); // student + instructor
});

it('revokes a role', function (): void {
    $user = User::factory()->withRole(RoleKey::Instructor)->create();
    expect($user->hasPermission('course.create'))->toBeTrue();

    $user->revokeRole(RoleKey::Instructor);

    expect($user->hasPermission('course.create'))->toBeFalse();
});

it('resolves permissions with a bounded number of queries', function (): void {
    $user = User::factory()->withRole(RoleKey::Instructor)->create()->fresh();

    DB::enableQueryLog();
    $user->hasPermission('course.create');
    $user->hasPermission('curriculum.reorder');
    $user->hasPermission('quiz.manage.own');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One eager load for assignments+roles+permissions, then pure memo.
    expect($queries)->toBeLessThanOrEqual(3);
});
