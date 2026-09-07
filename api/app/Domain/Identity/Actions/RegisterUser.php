<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\RegisterUserData;
use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Events\InstructorApplied;
use App\Domain\Identity\Events\UserRegistered;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterUser
{
    public function handle(RegisterUserData $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
            ]);

            $user->status = UserStatus::Active;
            $user->save();

            // Every account is a student. Teaching is additive and gated on
            // approval — signing up does not make you an instructor.
            $user->assignRole(RoleKey::Student);

            if ($data->wantsToTeach) {
                $profile = $user->instructorProfile()->create([
                    'status' => InstructorStatus::Pending,
                    'applied_at' => now(),
                    'application_source' => 'instructor_registration',
                ]);

                InstructorApplied::dispatch($profile);
            }

            return $user;
        });

        // The verification mail is queued by the listener, outside the
        // transaction — a mail failure must not roll back the account.
        UserRegistered::dispatch($user, $data->wantsToTeach);

        return $user;
    }
}
