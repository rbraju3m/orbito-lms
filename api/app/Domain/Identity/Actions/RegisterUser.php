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
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates an account INSIDE a named academy.
 *
 * The academy is a parameter because nothing else can supply it: tenancy
 * resolves from the authenticated user and this runs before there is one.
 * `ResolveSignupAcademy` decides which, and whether it accepts sign-ups.
 *
 * The ordering below is load-bearing, and is the same shape as
 * `ProvisionTenant`. `users` is CENTRAL; the role assignment and the
 * instructor profile are not. `tenancy()->initialize()` purges the connection
 * and discards any open transaction with it (§ Multi-tenancy), so the academy
 * cannot be opened from inside the central transaction — the row is committed
 * first and the tenant writes follow, with an explicit compensating delete if
 * they fail. A half-registered account that can sign in and has no role is
 * worse than no account.
 */
final class RegisterUser
{
    public function handle(RegisterUserData $data, Tenant $academy): User
    {
        $user = DB::transaction(function () use ($data, $academy): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
            ]);

            // Not fillable — which academy an account belongs to is decided
            // here, never by a request body.
            $user->forceFill([
                'status' => UserStatus::Active,
                'tenant_id' => $academy->id,
            ])->save();

            return $user;
        });

        try {
            $academy->run(fn () => $this->inAcademy($user, $data));
        } catch (Throwable $e) {
            // Compensate: the central row committed and will not roll back
            // with the academy's transaction.
            $user->forceDelete();

            throw $e;
        }

        // The verification mail is queued by the listener, outside the
        // transaction — a mail failure must not roll back the account.
        UserRegistered::dispatch($user, $data->wantsToTeach);

        return $user;
    }

    /** Runs with the academy's schema open. Everything here is tenant data. */
    private function inAcademy(User $user, RegisterUserData $data): void
    {
        // Every account is a student. Teaching is additive and gated on
        // approval — signing up does not make you an instructor.
        $user->assignRole(RoleKey::Student);

        if (! $data->wantsToTeach) {
            return;
        }

        $profile = $user->instructorProfile()->create([
            'status' => InstructorStatus::Pending,
            'applied_at' => now(),
            'application_source' => 'instructor_registration',
        ]);

        InstructorApplied::dispatch($profile);
    }
}
