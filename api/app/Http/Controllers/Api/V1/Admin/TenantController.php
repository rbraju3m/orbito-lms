<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Actions\AssignPlan;
use App\Domain\Platform\Actions\ChangeTenantStatus;
use App\Domain\Platform\Actions\ProvisionTenant;
use App\Domain\Platform\Data\NewAcademy;
use App\Domain\Platform\Enums\TenantAction;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Tenant;
use App\Http\Requests\Platform\AssignPlanRequest;
use App\Http\Requests\Platform\StoreTenantRequest;
use App\Http\Requests\Platform\TenantTransitionRequest;
use App\Http\Resources\Platform\SubscriptionResource;
use App\Http\Resources\Platform\TenantResource;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform operator's academy registry.
 *
 * Central-DB only, and OUTSIDE the `tenant` middleware — every route here is
 * about academies rather than inside one, and a suspended academy is exactly
 * the one an operator most needs to reach.
 */
final class TenantController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $tenants = Tenant::query()
            ->with('subscription.plan')
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', TenantStatus::from($request->string('status')->value())),
            )
            ->when(
                $request->filled('search'),
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', '%'.$request->string('search')->value().'%')
                    ->orWhere('slug', 'like', '%'.$request->string('search')->value().'%')),
            )
            ->orderByDesc('created_at')
            // `created_at` has second precision and a bulk import can tie, so
            // the id keeps a row off two pages. Same rule as the grading queue.
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponse::ok($tenants->through(
            fn (Tenant $tenant) => TenantResource::make($tenant)->resolve($request)
        ));
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        return ApiResponse::ok(
            TenantResource::make($tenant->load('subscription.plan'))->resolve($request)
        );
    }

    public function store(StoreTenantRequest $request, ProvisionTenant $action): JsonResponse
    {
        $tenant = $action->handle(new NewAcademy(
            slug: $request->string('slug')->value(),
            name: $request->string('name')->value(),
            ownerName: $request->string('owner_name')->value(),
            ownerEmail: $request->string('owner_email')->value(),
            ownerPassword: $request->string('owner_password')->value(),
            supportEmail: $request->string('support_email')->value() ?: null,
            plan: $request->filled('plan')
                ? Plan::where('slug', $request->string('plan')->value())->firstOrFail()
                : null,
        ));

        return ApiResponse::created(
            TenantResource::make($tenant->load('subscription.plan'))->resolve($request)
        );
    }

    /** Approve, reject, suspend or reactivate. One transition per request. */
    public function update(
        TenantTransitionRequest $request,
        Tenant $tenant,
        ChangeTenantStatus $action,
    ): JsonResponse {
        $reason = $request->string('reason')->value() ?: null;

        $updated = match ($request->action()) {
            TenantAction::Approve => $action->approve($tenant, $request->user()),
            TenantAction::Reject => $action->reject($tenant, $request->user(), $reason),
            TenantAction::Suspend => $action->suspend($tenant, $reason),
            TenantAction::Reactivate => $action->reactivate($tenant),
        };

        return ApiResponse::ok(
            TenantResource::make($updated->load('subscription.plan'))->resolve($request)
        );
    }

    /**
     * Move an academy onto a plan, and renew it.
     *
     * The operator's manual lever until Phase 10 puts a verified payment
     * behind it. Renewing revives a lapsed academy — otherwise paying would
     * change nothing until the nightly sweep, which only ever degrades.
     */
    public function assignPlan(
        AssignPlanRequest $request,
        Tenant $tenant,
        AssignPlan $action,
    ): JsonResponse {
        $endsAt = $request->filled('period_ends_at')
            ? CarbonImmutable::parse($request->string('period_ends_at')->value())
            : null;

        $subscription = $action->handle(
            $tenant,
            Plan::where('slug', $request->string('plan')->value())->firstOrFail(),
            $endsAt,
        );

        return ApiResponse::ok(SubscriptionResource::make($subscription)->resolve($request));
    }
}
