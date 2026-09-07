<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $passwordHash = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$passwordHash ??= Hash::make('password'),
            // Every nullable column is set explicitly: a factory model must be
            // as complete as a retrieved one, or strict mode trips on columns
            // the factory happened not to mention.
            'phone' => null,
            'headline' => null,
            'bio' => null,
            'last_login_at' => null,
            'last_seen_at' => null,
            'timezone' => 'UTC',
            'locale' => 'en',
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),

            /*
             * Users are CENTRAL and belong to exactly one academy. Defaulting
             * to whichever tenant is currently open is what keeps every
             * existing test working unchanged: they build a user, act as them,
             * and the tenant middleware resolves the same academy the test is
             * already inside.
             */
            'tenant_id' => tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null,
            'is_super_admin' => false,
        ];
    }

    /** A platform operator: no academy, central database only. */
    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'tenant_id' => null,
            'is_super_admin' => true,
        ]);
    }

    public function forTenant(?string $tenantId): static
    {
        return $this->state(fn () => ['tenant_id' => $tenantId]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    /** Attach a global role after creation. */
    public function withRole(RoleKey $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->assignRole(RoleKey::Student);

            if ($role !== RoleKey::Student) {
                $user->assignRole($role);
            }
        });
    }

    public function instructor(InstructorStatus $status = InstructorStatus::Approved): static
    {
        return $this->withRole($status->isActive() ? RoleKey::Instructor : RoleKey::Student)
            ->afterCreating(function (User $user) use ($status): void {
                $user->instructorProfile()->create([
                    'status' => $status,
                    'applied_at' => now(),
                    'reviewed_at' => $status === InstructorStatus::Pending ? null : now(),
                ]);
            });
    }
}
