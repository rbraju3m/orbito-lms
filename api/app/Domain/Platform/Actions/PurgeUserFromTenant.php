<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Removes a user's rows from their academy's schema.
 *
 * Exists because `users` is central and the rows that referenced it are not:
 * no foreign key can span two databases, so the `cascadeOnDelete` that used to
 * do this is gone. This reproduces it in application code.
 *
 * It runs on a HARD delete only. `User` is soft-deleting, and a soft delete
 * never cascaded either — the row is still there, and so is everything
 * pointing at it.
 *
 * The semantics below are the old foreign keys', deliberately, so that moving
 * to multi-tenancy changed no behaviour. Two of them are worth a product
 * decision that predates this change: deleting an instructor destroys the
 * COURSES they own, and with them every enrolled learner's progress. Cascading
 * that far from one account deletion is a sharp edge, but it is the sharp edge
 * the single-tenant schema already had.
 */
final class PurgeUserFromTenant
{
    /** Rows the user owned outright — the old cascadeOnDelete. */
    private const CASCADE = [
        'role_assignments' => 'user_id',
        'instructor_profiles' => 'user_id',
        'media' => 'owner_id',
        'courses' => 'owner_id',
        'course_instructors' => 'user_id',
        'quizzes' => 'owner_id',
        'question_banks' => 'owner_id',
        'assignment_submissions' => 'user_id',
        'quiz_attempts' => 'user_id',
        'enrollments' => 'user_id',
        'course_progress' => 'user_id',
        'item_progress' => 'user_id',
        'lesson_notes' => 'user_id',
    ];

    /** Rows that merely REFERENCE them — the old nullOnDelete. */
    private const NULLIFY = [
        'role_assignments' => 'granted_by',
        'instructor_profiles' => 'reviewed_by',
        'assignment_submissions' => 'graded_by',
        'quiz_attempts' => 'graded_by',
    ];

    public function handle(User $user): void
    {
        if ($user->tenant_id === null) {
            return; // A platform super-admin owns nothing inside an academy.
        }

        $tenant = Tenant::find($user->tenant_id);

        if ($tenant === null) {
            return; // The academy went first; its whole schema is already gone.
        }

        $tenant->run(function () use ($user): void {
            // Nullify BEFORE cascading. A grader is often also a learner, and
            // deleting their attempt rows first would leave nothing to clear
            // the graded_by pointer on somebody else's.
            DB::transaction(function () use ($user): void {
                foreach (self::NULLIFY as $table => $column) {
                    DB::table($table)->where($column, $user->id)->update([$column => null]);
                }

                foreach (self::CASCADE as $table => $column) {
                    DB::table($table)->where($column, $user->id)->delete();
                }
            });
        });
    }
}
