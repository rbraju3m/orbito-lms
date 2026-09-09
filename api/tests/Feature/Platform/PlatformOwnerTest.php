<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\SuspendUser;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\EnsurePlatformOwner;
use App\Domain\Platform\Actions\ProvisionTenant;
use App\Domain\Platform\Data\NewAcademy;
use App\Domain\Platform\Exceptions\PlatformOwnerProtected;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SwitchesTenants;

// EnsurePlatformOwner walks the academies, and Tenant::run() purges the
// connection along with any transaction open on it.
uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();

    $this->ownerEmail = (string) config('orbito.owner.email');
    $this->ownerPassword = (string) config('orbito.owner.password');
});

function ensureOwner(): User
{
    $owner = app(EnsurePlatformOwner::class)->handle();

    expect($owner)->not->toBeNull();

    return $owner;
}

function currentAcademy(): Tenant
{
    return Tenant::findOrFail(tenancy()->tenant->getTenantKey());
}

describe('creation', function (): void {
    it('creates one account that is both a platform operator and an academy super admin', function (): void {
        $owner = ensureOwner();

        expect($owner->email)->toBe($this->ownerEmail)
            ->and($owner->name)->toBe((string) config('orbito.owner.name'))
            // The central flag: opens the academy registry.
            ->and($owner->is_super_admin)->toBeTrue()
            ->and($owner->status)->toBe(UserStatus::Active)
            ->and($owner->email_verified_at)->not->toBeNull()
            ->and(Hash::check($this->ownerPassword, $owner->password))->toBeTrue();

        $academy = currentAcademy();

        // The ROLE, which is a different thing entirely and lives inside the
        // academy's own schema.
        expect($academy->run(fn (): bool => $owner->fresh()->hasRole(RoleKey::SuperAdmin)))
            ->toBeTrue()
            // And they have been put inside an academy, so the product screens
            // resolve rather than sitting on an empty central connection.
            ->and($owner->tenant_id)->toBe($academy->id);
    });

    it('is idempotent', function (): void {
        ensureOwner();
        ensureOwner();

        expect(User::where('email', $this->ownerEmail)->count())->toBe(1);

        $academy = currentAcademy();

        expect($academy->run(fn (): int => User::where('email', $this->ownerEmail)
            ->firstOrFail()
            ->roleAssignments()
            ->count()))->toBe(1);
    });

    it('never rewrites a password the owner has changed', function (): void {
        $owner = ensureOwner();

        $owner->forceFill(['password' => 'a-password-they-chose'])->save();

        ensureOwner();

        expect(Hash::check('a-password-they-chose', $owner->fresh()->password))->toBeTrue()
            ->and(Hash::check($this->ownerPassword, $owner->fresh()->password))->toBeFalse();
    });

    it('repairs an account somebody has damaged', function (): void {
        $owner = ensureOwner();

        // Everything a determined operator could still do from a console.
        $owner->forceFill(['is_super_admin' => false, 'status' => UserStatus::Suspended])->save();
        currentAcademy()->run(fn () => $owner->revokeRole(RoleKey::SuperAdmin));

        $repaired = ensureOwner();

        expect($repaired->is_super_admin)->toBeTrue()
            ->and($repaired->status)->toBe(UserStatus::Active)
            ->and(currentAcademy()->run(fn (): bool => $repaired->fresh()->hasRole(RoleKey::SuperAdmin)))
            ->toBeTrue();
    });

    it('grants super admin inside an academy provisioned later', function (): void {
        ensureOwner();

        Plan::factory()->create(['slug' => 'later-plan', 'position' => 0]);

        $tenant = app(ProvisionTenant::class)->handle(new NewAcademy(
            slug: 'later-college',
            name: 'Later College',
            ownerName: 'Ada Lovelace',
            ownerEmail: 'ada@later.test',
            ownerPassword: 'correct-horse-battery-staple',
        ));

        // Provisioning seeds the role, which is why a brand-new academy — still
        // Pending, and therefore skipped by the walk — is covered too.
        expect($tenant->run(fn (): bool => User::where('email', $this->ownerEmail)
            ->firstOrFail()
            ->hasRole(RoleKey::SuperAdmin)))->toBeTrue();
    });
});

describe('protection', function (): void {
    it('refuses to soft-delete the owner', function (): void {
        $owner = ensureOwner();

        expect(fn () => $owner->delete())->toThrow(PlatformOwnerProtected::class);
        expect(User::where('email', $this->ownerEmail)->exists())->toBeTrue();
    });

    it('refuses to force-delete the owner', function (): void {
        $owner = ensureOwner();

        expect(fn () => $owner->forceDelete())->toThrow(PlatformOwnerProtected::class);
        expect(User::where('email', $this->ownerEmail)->exists())->toBeTrue();
    });

    it('refuses to suspend the owner, even for another super admin', function (): void {
        $owner = ensureOwner();
        $other = User::factory()->withRole(RoleKey::SuperAdmin)->create();

        // Gate::before grants a Super Admin everything EXCEPT this: a blanket
        // bypass would never reach the policy that refuses.
        $this->actingAs($other)
            ->postJson("/api/v1/admin/users/{$owner->uuid}/suspension", ['suspended' => true])
            ->assertStatus(403);

        expect($owner->fresh()->status)->toBe(UserStatus::Active);
    });

    it('refuses to suspend the owner from the Action, which nothing authorizes', function (): void {
        $owner = ensureOwner();

        expect(fn () => app(SuspendUser::class)->handle($owner, true))
            ->toThrow(PlatformOwnerProtected::class);
    });

    it('refuses to revoke the owner’s super admin role', function (): void {
        $owner = ensureOwner();
        $other = User::factory()->withRole(RoleKey::SuperAdmin)->create();

        expect($this->actingAs($other)
            ->deleteJson("/api/v1/admin/users/{$owner->uuid}/roles/super_admin")
            ->assertStatus(422))
            ->toBeApiError('platform_owner_protected');

        expect($owner->fresh()->hasRole(RoleKey::SuperAdmin))->toBeTrue();
    });

    it('still allows the harmless things a super admin may do to any account', function (): void {
        $owner = ensureOwner();
        $other = User::factory()->withRole(RoleKey::SuperAdmin)->create();

        // The Gate::before exception falls THROUGH to the policy; it does not
        // deny outright, or the owner would be invisible in the user list.
        $this->actingAs($other)->getJson("/api/v1/admin/users/{$owner->uuid}")->assertOk();
    });
});

describe('entering and leaving an academy', function (): void {
    it('resolves the entered academy’s schema on an ordinary endpoint', function (): void {
        $owner = ensureOwner();

        // `admin/users` is a TENANT route behind a permission the owner only
        // holds through the academy role. A 200 proves both halves.
        $this->actingAs($owner)->getJson('/api/v1/admin/users')->assertOk();
    });

    it('reports which academy the caller is inside', function (): void {
        $owner = ensureOwner();
        $academy = currentAcademy();

        $response = $this->actingAs($owner)->getJson('/api/v1/auth/me')->assertOk();

        expect($response->json('data.is_platform_operator'))->toBeTrue()
            ->and($response->json('data.is_platform_owner'))->toBeTrue()
            ->and($response->json('data.academy.slug'))->toBe($academy->slug);
    });

    it('leaves an academy and enters it again', function (): void {
        $owner = ensureOwner();
        $academy = currentAcademy();

        $this->actingAs($owner)->postJson('/api/v1/admin/tenants/leave')->assertOk();
        expect($owner->fresh()->tenant_id)->toBeNull();

        $this->actingAs($owner)
            ->postJson("/api/v1/admin/tenants/{$academy->slug}/enter")
            ->assertOk()
            ->assertJsonPath('data.slug', $academy->slug);

        expect($owner->fresh()->tenant_id)->toBe($academy->id);
    });

    it('denies entering an academy to anyone who is not a platform operator', function (): void {
        $academy = currentAcademy();
        $admin = User::factory()->withRole(RoleKey::SuperAdmin)->create();

        // An academy Super Admin is not a platform operator. Similar names,
        // unrelated powers.
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/tenants/{$academy->slug}/enter")
            ->assertStatus(403);
    });
});
