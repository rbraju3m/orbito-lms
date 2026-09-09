<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\PurgeUserFromTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `users` is central and everything referencing it is not, so no foreign key
 * can span the two databases and `PurgeUserFromTenant` reproduces the old
 * cascade in application code.
 *
 * Its two maps are hand-written against a schema that no longer enforces them.
 * Nothing but this file stops one drifting: `quizzes.owner_id` sat in the
 * cascade list and has never existed — a quiz is a settings row hung off a
 * course item, not something a user owns — so every hard delete of an academy
 * member died with "Unknown column 'owner_id'". It was found by deleting a
 * real account, not by the suite.
 */
beforeEach(fn () => seedRegistry());

it('names only columns that exist', function (): void {
    $missing = [];

    foreach ([PurgeUserFromTenant::CASCADE, PurgeUserFromTenant::NULLIFY] as $map) {
        foreach ($map as $table => $column) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                $missing[] = "{$table} (no such table)";

                continue;
            }

            if (! Schema::connection('tenant')->hasColumn($table, $column)) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('hard-deletes an academy member and takes their rows with them', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();
    $id = $user->id;

    expect(DB::table('role_assignments')->where('user_id', $id)->count())->toBe(1);

    $user->forceDelete();

    expect(User::withTrashed()->find($id))->toBeNull()
        ->and(DB::table('role_assignments')->where('user_id', $id)->count())->toBe(0);
});

it('takes the courses an instructor owned', function (): void {
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->for($instructor, 'owner')->create();

    $instructor->forceDelete();

    expect(Course::find($course->id))->toBeNull();
});

/*
 * A soft delete never cascaded either — the row is still there, and so is
 * everything pointing at it. Only `forceDeleted` fires the purge.
 */
it('leaves everything alone on a soft delete', function (): void {
    $user = User::factory()->withRole(RoleKey::Student)->create();

    $user->delete();

    expect(DB::table('role_assignments')->where('user_id', $user->id)->count())->toBe(1);
});
