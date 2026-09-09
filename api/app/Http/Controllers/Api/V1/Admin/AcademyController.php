<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Actions\UpdateAcademySettings;
use App\Domain\Platform\Models\Tenant;
use App\Http\Requests\Platform\UpdateAcademyRequest;
use App\Http\Resources\Platform\AcademyResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The academy administering ITSELF — not the platform registry.
 *
 * There is no `{academy}` in the path, deliberately. The caller's own academy
 * is the only one they may touch, and it is already resolved: the `tenant`
 * middleware opened it from their `tenant_id`. Accepting an id here would be
 * an IDOR with extra steps.
 */
final class AcademyController
{
    public function show(Request $request): JsonResponse
    {
        $academy = $this->current($request);

        Gate::authorize('view', $academy);

        return ApiResponse::ok(AcademyResource::make($academy)->resolve($request));
    }

    public function update(UpdateAcademyRequest $request, UpdateAcademySettings $action): JsonResponse
    {
        $academy = $this->current($request);

        Gate::authorize('update', $academy);

        /** @var array{registration_mode?: string, support_email?: string|null} $changes */
        $changes = $request->validated();

        return ApiResponse::ok(
            AcademyResource::make($action->handle($academy, $changes))->resolve($request)
        );
    }

    private function current(Request $request): Tenant
    {
        $academy = Tenant::find($request->user()->tenant_id);

        // A platform operator inside no academy never reaches this — the
        // `tenant` middleware answers 409 first. This covers the narrower
        // case of a row deleted mid-session.
        if ($academy === null) {
            throw new NotFoundHttpException;
        }

        return $academy;
    }
}
