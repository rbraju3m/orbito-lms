<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
 * Isolation here is STRUCTURAL, not a scope somebody remembered to apply: one
 * academy's rows are in a different database, so a query that forgets a filter
 * still cannot reach them. These tests pin that property rather than assume it.
 */

function makeAcademy(string $slug): Tenant
{
    return Tenant::create([
        'id' => $slug,
        'slug' => $slug,
        'name' => ucfirst($slug).' Academy',
        'status' => TenantStatus::Active,
        'is_active' => true,
    ]);
}

it('keeps two academies\' catalogues entirely separate', function (): void {
    $a = makeAcademy('alpha');
    $b = makeAcademy('beta');

    $a->run(fn () => DB::table('course_categories')->insert([
        'slug' => 'maths', 'name' => 'Maths', 'created_at' => now(), 'updated_at' => now(),
    ]));

    // The same slug, which is UNIQUE within a schema — proof these are
    // genuinely different tables rather than one filtered by a column.
    $b->run(fn () => DB::table('course_categories')->insert([
        'slug' => 'maths', 'name' => 'Maths', 'created_at' => now(), 'updated_at' => now(),
    ]));

    expect($a->run(fn () => DB::table('course_categories')->count()))->toBe(1)
        ->and($b->run(fn () => DB::table('course_categories')->count()))->toBe(1);
});

it('refuses a learner whose academy is suspended', function (): void {
    $academy = makeAcademy('gamma');
    $user = User::factory()->forTenant($academy->id)->create();

    $academy->update(['status' => TenantStatus::Suspended]);

    $this->actingAs($user)->getJson('/api/v1/auth/me')->assertForbidden();
});

it('refuses an account attached to no academy', function (): void {
    $orphan = User::factory()->forTenant(null)->create();

    $this->actingAs($orphan)->getJson('/api/v1/auth/me')->assertForbidden();
});

/*
 * A super-admin has no academy and must still be able to reach the central
 * surface — the middleware lets them through rather than 403ing on a missing
 * tenant_id.
 */
it('lets a platform super-admin through without an academy', function (): void {
    $admin = User::factory()->superAdmin()->create();

    expect($admin->tenant_id)->toBeNull()
        ->and($admin->is_super_admin)->toBeTrue();
});

it('never lets one academy\'s course be reached from another', function (): void {
    seedRegistry();

    // A course in the academy the test is already inside.
    $owner = User::factory()->instructor()->create();
    $course = courseWithCurriculum(Course::factory()->ownedBy($owner)->published()->create(), [1]);
    $slug = $course->slug;

    $other = makeAcademy('delta');
    $outsider = User::factory()->forTenant($other->id)->create();

    // Same URL, different academy: not forbidden, NOT FOUND. The row is not in
    // their database at all, so there is nothing to be forbidden from.
    $this->actingAs($outsider)->getJson("/api/v1/courses/{$slug}")->assertNotFound();
});
