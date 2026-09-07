<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Events\InstructorApplied;
use App\Domain\Identity\Exceptions\InstructorApplicationConflict;
use App\Domain\Identity\Models\InstructorProfile;
use App\Domain\Identity\Models\User;

final class ApplyAsInstructor
{
    public function handle(User $user, ?string $message = null): InstructorProfile
    {
        $user->loadMissing('instructorProfile');
        $existing = $user->instructorProfile;

        if ($existing !== null) {
            match ($existing->status) {
                InstructorStatus::Pending => throw InstructorApplicationConflict::alreadyApplied(),
                InstructorStatus::Approved => throw InstructorApplicationConflict::alreadyApproved(),
                InstructorStatus::Blocked => throw InstructorApplicationConflict::blocked(),
                // A rejected applicant may reapply.
                InstructorStatus::Rejected => null,
            };
        }

        $profile = $user->instructorProfile()->updateOrCreate([], [
            'status' => InstructorStatus::Pending,
            'applied_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'review_note' => null,
            'application_source' => 'student_dashboard',
            'application_message' => $message,
        ]);

        InstructorApplied::dispatch($profile);

        return $profile;
    }
}
