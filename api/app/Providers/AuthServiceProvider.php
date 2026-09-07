<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\InstructorProfile;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\InstructorProfilePolicy;
use App\Domain\Identity\Policies\RolePolicy;
use App\Domain\Identity\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private array $policies = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        InstructorProfile::class => InstructorProfilePolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerMorphMap();
        $this->registerSuperAdminBypass();
    }

    /**
     * Scope types are stored as short aliases, not FQCNs — `role_assignments`
     * rows must survive a class being moved between namespaces.
     */
    private function registerMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            // 'course' => Course::class,  ← Phase 4
        ]);
    }

    /**
     * The ONLY blanket authorization bypass in the system.
     *
     * Returning null (not false) for everyone else lets the normal policy chain
     * run — returning false here would deny everything.
     */
    private function registerSuperAdminBypass(): void
    {
        Gate::before(function (User $user): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
    }
}
