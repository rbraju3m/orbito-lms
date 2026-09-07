<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Data\NewAcademy;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Events\TenantProvisioned;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates an academy: the row, its schema, its owner and its subscription.
 *
 * Ordering is load-bearing and not obvious.
 *
 * `Tenant::create()` fires TenantCreated, which provisions and migrates and
 * seeds the schema SYNCHRONOUSLY (see TenancyServiceProvider). That is a DDL
 * sequence and MySQL commits DDL implicitly, so it cannot sit inside the
 * central transaction — a rollback would leave the schema behind with no row
 * pointing at it, and the next attempt on the same id would fail to create a
 * database that already exists.
 *
 * So the schema is built first and the central rows follow. If the central
 * write fails, the compensating drop is explicit rather than implied.
 */
final class ProvisionTenant
{
    public function handle(NewAcademy $academy): Tenant
    {
        $plan = $academy->plan ?? $this->defaultPlan();

        $tenant = Tenant::create([
            'id' => (string) Str::uuid7(),
            'slug' => $academy->slug,
            'name' => $academy->name,
            // Provisioned but shut until a super-admin approves it. A schema
            // that exists is not the same as an academy that is open.
            'status' => TenantStatus::Pending,
            'is_active' => true,
            'support_email' => $academy->supportEmail,
        ]);

        try {
            $owner = DB::transaction(function () use ($tenant, $academy, $plan): User {
                $owner = User::create([
                    'name' => $academy->ownerName,
                    'email' => $academy->ownerEmail,
                    'password' => $academy->ownerPassword,
                ]);

                // Not fillable — being attached to an academy is decided by
                // provisioning, never by a request body.
                $owner->forceFill(['tenant_id' => $tenant->id])->save();

                $this->startSubscription($tenant, $plan);

                return $owner;
            });
        } catch (Throwable $e) {
            // Compensate: the schema was committed by DDL and will not roll
            // back with the transaction above.
            $tenant->delete();

            throw $e;
        }

        // Roles live inside the academy, so the owner's role assignment does
        // too — and can only be written once the schema exists.
        $tenant->run(fn () => $owner->assignRole(RoleKey::Admin));

        TenantProvisioned::dispatch($tenant, $owner);

        return $tenant->refresh();
    }

    private function startSubscription(Tenant $tenant, Plan $plan): Subscription
    {
        $trialEnds = $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null;

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $trialEnds !== null
                ? SubscriptionStatus::Trialing
                : SubscriptionStatus::Active,
            'trial_ends_at' => $trialEnds,
            'current_period_starts_at' => now(),
            'current_period_ends_at' => $trialEnds ?? now()->addMonth(),
            // Copied, not read through the plan: changing a plan's grace
            // period must not re-open academies that already lapsed.
            'grace_days' => $plan->grace_days,
        ]);
    }

    private function defaultPlan(): Plan
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->firstOrFail();
    }
}
