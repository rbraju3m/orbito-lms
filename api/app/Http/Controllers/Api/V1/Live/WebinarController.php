<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\Live\Actions\RegisterForWebinar;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Http\Resources\Live\WebinarResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Standalone live events.
 *
 * MEMBERS-ONLY, and that follows from the tenancy design rather than from a
 * product decision: tenancy resolves from the authenticated user, so there is
 * no anonymous surface to register from. A webinar open to the whole academy
 * is still the one live format not gated on buying a course, and the public
 * path arrives with the marketing site in P16 — when there will be somewhere
 * to register FROM.
 */
final class WebinarController
{
    public function index(Request $request): JsonResponse
    {
        $canManage = Gate::allows('manage-webinars');

        $webinars = Webinar::query()
            ->with('session')
            ->withCount('registrations')
            // Drafts only for the people who can publish them.
            ->unless($canManage, fn ($query) => $query->published())
            ->orderBy('created_at', 'desc')
            ->paginate($this->perPage($request));

        $registered = $this->registeredIds($request, $webinars->pluck('id')->all());

        return ApiResponse::ok(
            WebinarResource::collection($webinars->through(
                fn (Webinar $webinar) => new WebinarResource(
                    $webinar,
                    in_array($webinar->id, $registered, true),
                ),
            ))->additional(['meta' => ['can_manage' => $canManage]]),
        );
    }

    public function show(Request $request, Webinar $webinar): JsonResponse
    {
        abort_unless(
            $webinar->status->isOpen() || Gate::allows('manage-webinars'),
            404,
        );

        return ApiResponse::ok(new WebinarResource(
            $webinar->load('session')->loadCount('registrations'),
            $this->registeredIds($request, [$webinar->id]) !== [],
        ));
    }

    public function register(Request $request, Webinar $webinar, RegisterForWebinar $action): JsonResponse
    {
        $action->handle($request->user(), $webinar);

        return ApiResponse::created(new WebinarResource(
            $webinar->fresh()->load('session')->loadCount('registrations'),
            isRegistered: true,
        ));
    }

    /** Cancelling frees the place rather than deleting the record of it. */
    public function cancel(Request $request, Webinar $webinar): JsonResponse
    {
        WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->where('email', $request->user()->email)
            ->update(['status' => WebinarRegistration::STATUS_CANCELLED]);

        return ApiResponse::ok(new WebinarResource(
            $webinar->fresh()->load('session')->loadCount('registrations'),
            isRegistered: false,
        ));
    }

    /**
     * @param  list<int>  $webinarIds
     * @return list<int>
     */
    private function registeredIds(Request $request, array $webinarIds): array
    {
        if ($webinarIds === []) {
            return [];
        }

        return WebinarRegistration::query()
            ->whereIn('webinar_id', $webinarIds)
            // Matched on EMAIL, like the unique key, so a registration made
            // before somebody had an account still counts as theirs.
            ->where('email', $request->user()->email)
            ->where('status', WebinarRegistration::STATUS_REGISTERED)
            ->pluck('webinar_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
