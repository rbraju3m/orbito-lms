<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        if (! app()->environment('local', 'testing')) {
            return;
        }

        $this->demoUser('Super Admin', 'super@orbito.test', RoleKey::SuperAdmin);
        $this->demoUser('Platform Admin', 'admin@orbito.test', RoleKey::Admin);
        $this->demoUser('Support Staff', 'staff@orbito.test', RoleKey::Staff);
        $this->demoUser('Student One', 'student@orbito.test', RoleKey::Student);

        $instructor = $this->demoUser('Instructor One', 'instructor@orbito.test', RoleKey::Instructor);
        $instructor->instructorProfile()->updateOrCreate([], [
            'status' => InstructorStatus::Approved,
            'applied_at' => now(),
            'reviewed_at' => now(),
        ]);

        $applicant = $this->demoUser('Pending Instructor', 'applicant@orbito.test', RoleKey::Student);
        $applicant->instructorProfile()->updateOrCreate([], [
            'status' => InstructorStatus::Pending,
            'applied_at' => now(),
            'application_source' => 'instructor_registration',
        ]);
    }

    private function demoUser(string $name, string $email, RoleKey $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password', 'email_verified_at' => now()],
        );

        // Everyone is a student; elevated roles are additive.
        $user->assignRole(RoleKey::Student);

        if ($role !== RoleKey::Student) {
            $user->assignRole($role);
        }

        return $user->fresh() ?? $user;
    }
}
