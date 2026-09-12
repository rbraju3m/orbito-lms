<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Live\Actions\ConnectLiveProvider;
use App\Domain\Live\Actions\DisconnectLiveProvider;
use App\Domain\Live\Enums\LiveProvider;
use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Live\Models\LiveProviderAccount;
use App\Domain\Live\Models\LiveSession;
use App\Http\Requests\Live\ConnectLiveProviderRequest;
use App\Http\Resources\Live\LiveProviderAccountResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An academy connecting its OWN meeting provider.
 *
 * The platform holds no Zoom or Google account on an academy's behalf — the
 * same decision payment gateways made in ADR-13 — which is why the row lives
 * in the tenant schema and why the capability belongs to an academy admin
 * rather than to an instructor. Scheduling a class is an instructor's job;
 * handing the academy's Zoom credentials to every instructor is not.
 */
final class LiveProviderController
{
    /** Every provider the platform supports, connected or not. */
    public function index(): JsonResponse
    {
        Gate::authorize('manage-live-providers');

        $connected = LiveProviderAccount::query()->get()->keyBy(
            fn (LiveProviderAccount $account): string => $account->provider->value,
        );

        $upcoming = $this->upcomingSessions();

        $rows = array_map(function (LiveProvider $provider) use ($connected, $upcoming): array {
            $sessions = (int) ($upcoming[$provider->value] ?? 0);
            $account = $connected->get($provider->value);

            if (! $account instanceof LiveProviderAccount) {
                return LiveProviderAccountResource::unconnected($provider, $sessions);
            }

            $account->setAttribute('upcoming_sessions_count', $sessions);

            return LiveProviderAccountResource::make($account)->resolve(request());
        }, LiveProvider::cases());

        return ApiResponse::ok($rows);
    }

    public function update(
        ConnectLiveProviderRequest $request,
        string $provider,
        ConnectLiveProvider $action,
    ): JsonResponse {
        Gate::authorize('manage-live-providers');

        $account = $action->handle(
            provider: $this->resolve($provider),
            credentials: $request->credentials(),
            isActive: $request->has('is_active') ? $request->boolean('is_active') : null,
        );

        return ApiResponse::ok(LiveProviderAccountResource::make($account));
    }

    public function destroy(string $provider, DisconnectLiveProvider $action): JsonResponse
    {
        Gate::authorize('manage-live-providers');

        $action->handle($this->resolve($provider));

        return ApiResponse::noContent();
    }

    /**
     * How many scheduled sessions each provider still has ahead of it.
     *
     * One grouped query rather than one per provider, and `toBase()` because
     * a row of `COUNT(*)` is not a LiveSession (§ Patterns established in
     * Phase 13).
     *
     * @return array<string, int>
     */
    private function upcomingSessions(): array
    {
        /** @var array<string, int> $counts */
        $counts = LiveSession::query()
            ->where('status', SessionStatus::Scheduled)
            ->where('starts_at', '>=', now())
            ->toBase()
            ->selectRaw('provider, COUNT(*) as total')
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->all();

        return $counts;
    }

    private function resolve(string $provider): LiveProvider
    {
        return LiveProvider::tryFrom($provider) ?? throw new NotFoundHttpException;
    }
}
