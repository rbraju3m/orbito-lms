<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Enums\TenantAction;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;
use Tests\Concerns\SwitchesTenants;

// Provisioning creates real schemas, and Tenant::run() purges the connection.
uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();

    $this->plan = Plan::factory()->create(['slug' => 'growth', 'trial_days' => 14, 'position' => 0]);
    $this->admin = User::factory()->superAdmin()->create();
});

function newAcademyPayload(array $overrides = []): array
{
    return array_merge([
        'slug' => 'north-college',
        'name' => 'North College',
        'owner_name' => 'Ada Lovelace',
        'owner_email' => 'ada@north.test',
        'owner_password' => 'correct-horse-battery-staple',
    ], $overrides);
}

describe('provisioning', function (): void {
    it('creates the academy, its schema, its owner and its subscription', function (): void {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/tenants', newAcademyPayload())
            ->assertCreated();

        expect($response->json('data.slug'))->toBe('north-college')
            // Provisioned but SHUT: a schema existing is not an academy being open.
            ->and($response->json('data.status'))->toBe('pending')
            ->and($response->json('data.is_open'))->toBeFalse();

        $tenant = Tenant::where('slug', 'north-college')->firstOrFail();

        expect($tenant->database()->manager()->databaseExists($tenant->database()->getName()))
            ->toBeTrue();

        $owner = User::where('email', 'ada@north.test')->firstOrFail();
        expect($owner->tenant_id)->toBe($tenant->id);

        expect(Subscription::where('tenant_id', $tenant->id)->first()?->status)
            ->toBe(SubscriptionStatus::Trialing);
    });

    it('gives the owner an admin role inside their own academy', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/tenants', newAcademyPayload())
            ->assertCreated();

        $tenant = Tenant::where('slug', 'north-college')->firstOrFail();
        $owner = User::where('email', 'ada@north.test')->firstOrFail();

        // The role lives in the academy's schema, so it can only be read there.
        expect($tenant->run(fn () => $owner->hasRole(RoleKey::Admin)))->toBeTrue();
    });

    it('refuses a duplicate slug', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/tenants', newAcademyPayload());

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/tenants', newAcademyPayload(['owner_email' => 'other@north.test']))
            ->assertStatus(422);
    });

    it('refuses an email already used anywhere on the platform', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/tenants', newAcademyPayload());

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/tenants', newAcademyPayload(['slug' => 'south-college']))
            ->assertStatus(422);
    });

    it('rejects a slug that is not url-safe', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/tenants', newAcademyPayload(['slug' => 'North College!']))
            ->assertStatus(422);
    });

    it('starts active with no trial when the plan has none', function (): void {
        Plan::factory()->withoutTrial()->create(['slug' => 'free', 'position' => 5]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/tenants', newAcademyPayload(['plan' => 'free']))
            ->assertCreated();

        $tenant = Tenant::where('slug', 'north-college')->firstOrFail();

        expect(Subscription::where('tenant_id', $tenant->id)->first()?->status)
            ->toBe(SubscriptionStatus::Active);
    });
});

describe('the lifecycle', function (): void {
    beforeEach(function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/tenants', newAcademyPayload());
        $this->tenant = Tenant::where('slug', 'north-college')->firstOrFail();
    });

    it('approves a pending academy and opens it', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_open', true);

        expect(Tenant::find($this->tenant->id)->approved_by)->toBe($this->admin->id);
    });

    it('refuses to approve an academy that is not pending', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'approve']);

        expect($this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'approve'])
            ->assertStatus(409))->toBeApiError('tenant_transition_rejected');
    });

    it('suspends and reactivates without touching the schema', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'approve']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", [
                'action' => 'suspend', 'reason' => 'Chargeback',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspended_reason', 'Chargeback');

        // The database is still there — reinstating must not need a restore.
        $fresh = Tenant::find($this->tenant->id);
        expect($fresh->database()->manager()->databaseExists($fresh->database()->getName()))->toBeTrue();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'reactivate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    });

    it('shuts a suspended academy out of its own API', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'approve']);

        $owner = User::where('email', 'ada@north.test')->firstOrFail();
        $this->actingAs($owner)->getJson('/api/v1/auth/me')->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'suspend']);

        $this->actingAs($owner)->getJson('/api/v1/auth/me')->assertForbidden();
    });

    it('rejects an unknown transition', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'obliterate'])
            ->assertStatus(422);
    });
});

describe('who may reach the registry', function (): void {
    it('lists academies for a platform operator', function (): void {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/tenants')->assertOk();

        expect($response->json('meta.total'))->toBeGreaterThanOrEqual(1);
    });

    it('filters by status', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/tenants', newAcademyPayload());

        expect($this->actingAs($this->admin)
            ->getJson('/api/v1/admin/tenants?status=pending')->assertOk()->json('meta.total'))
            ->toBe(1);
    });

    /*
     * The important denial. An academy admin holds every role inside their own
     * academy and none of it reaches the platform — the operator surface is a
     * central flag, not a role, precisely because roles live in a schema.
     */
    it('denies an academy admin', function (): void {
        $tenantAdmin = userWithRole(RoleKey::Admin);

        $this->actingAs($tenantAdmin)->getJson('/api/v1/admin/tenants')->assertForbidden();
        $this->actingAs($tenantAdmin)
            ->postJson('/api/v1/admin/tenants', newAcademyPayload())->assertForbidden();
    });

    it('denies an ordinary member', function (): void {
        $this->actingAs(User::factory()->withRole(RoleKey::Student)->create())
            ->getJson('/api/v1/admin/tenants')->assertForbidden();
    });

    it('requires authentication', function (): void {
        $this->getJson('/api/v1/admin/tenants')->assertStatus(401);
    });
});

/*
 * The registry screens render a button per available action. If this list and
 * ChangeTenantStatus ever disagree, an operator gets a button that 409s — so
 * they come from one definition (TenantStatus::allows) and these tests assert
 * the two halves agree.
 */
describe('the actions a row offers', function (): void {
    beforeEach(function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/tenants', newAcademyPayload());
        $this->tenant = Tenant::where('slug', 'north-college')->firstOrFail();
    });

    /*
     * Suspend is offered on a PENDING academy, which reads oddly until you
     * remember what suspension is for: closing access to a signup that turned
     * out to be abusive, without rejecting it outright. ChangeTenantStatus has
     * always allowed it — this asserts the button matches, rather than the
     * screen quietly deciding otherwise.
     */
    it('offers approve, reject and suspend on a pending academy', function (): void {
        expect($this->actingAs($this->admin)
            ->getJson("/api/v1/admin/tenants/{$this->tenant->slug}")
            ->assertOk()
            ->json('data.available_actions'))
            ->toEqualCanonicalizing(['approve', 'reject', 'suspend']);
    });

    it('offers only suspend once an academy is open', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'approve']);

        expect($this->actingAs($this->admin)
            ->getJson("/api/v1/admin/tenants/{$this->tenant->slug}")
            ->json('data.available_actions'))
            ->toEqualCanonicalizing(['suspend']);
    });

    it('offers nothing on a rejected academy', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/tenants/{$this->tenant->slug}", ['action' => 'reject']);

        expect($this->actingAs($this->admin)
            ->getJson("/api/v1/admin/tenants/{$this->tenant->slug}")
            ->json('data.available_actions'))
            ->toBe([]);
    });

    it('never offers an action the write endpoint would refuse', function (): void {
        foreach (TenantStatus::cases() as $status) {
            $this->tenant->forceFill(['status' => $status])->save();

            $offered = $this->actingAs($this->admin)
                ->getJson("/api/v1/admin/tenants/{$this->tenant->slug}")
                ->json('data.available_actions');

            foreach (TenantAction::cases() as $action) {
                // The list is exactly what the Action will accept — asserted
                // against the enum both of them read, in both directions.
                expect(in_array($action->value, $offered, true))
                    ->toBe($status->allows($action));
            }
        }
    });
});

describe('the plan list', function (): void {
    it('returns the plans an academy can be put on, in display order', function (): void {
        Plan::factory()->create(['slug' => 'zzz-late', 'name' => 'Late', 'position' => 900]);
        Plan::factory()->create(['slug' => 'aaa-early', 'name' => 'Early', 'position' => 1]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/plans')->assertOk();

        $slugs = $response->json('data.*.slug');

        // `position`, not the slug and not the id: an operator curates the
        // order these appear in.
        expect(array_search('aaa-early', $slugs, true))
            ->toBeLessThan(array_search('zzz-late', $slugs, true));

        expect($response->json('data.0'))->toHaveKeys([
            'slug', 'name', 'price_minor', 'currency', 'limits', 'is_active',
        ]);
    });

    it('denies it to an academy admin', function (): void {
        $this->actingAs(userWithRole(RoleKey::Admin))
            ->getJson('/api/v1/admin/plans')->assertForbidden();
    });
});
