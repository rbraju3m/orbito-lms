<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\Live\Actions\ChangeWebinarStatus;
use App\Domain\Live\Actions\CreateWebinar;
use App\Domain\Live\Actions\DeleteWebinar;
use App\Domain\Live\Actions\RegisterForWebinar;
use App\Domain\Live\Actions\UpdateWebinar;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Providers\LiveProviderFactory;
use App\Http\Requests\Live\StoreWebinarRequest;
use App\Http\Resources\Live\WebinarResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
    public function index(Request $request, LiveProviderFactory $providers): JsonResponse
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
                    $canManage,
                ),
            ))->additional(['meta' => [
                'can_manage' => $canManage,
                // The same list the course session form is built from, and the
                // same one scheduling enforces. Only for somebody who could
                // schedule — it costs a query per provider.
                'providers' => $canManage ? $providers->options() : [],
            ]]),
        );
    }

    public function show(Request $request, Webinar $webinar): JsonResponse
    {
        $canManage = Gate::allows('manage-webinars');

        abort_unless($webinar->status->isOpen() || $canManage, 404);

        return ApiResponse::ok(new WebinarResource(
            $webinar->load('session')->loadCount('registrations'),
            $this->registeredIds($request, [$webinar->id]) !== [],
            $canManage,
        ));
    }

    /**
     * Creating one — the webinar and the session it happens at, in one act.
     *
     * A DRAFT, always: publishing is its own decision, so nobody puts an
     * event in front of the academy by filling in a form and pressing save.
     */
    public function store(StoreWebinarRequest $request, CreateWebinar $action): JsonResponse
    {
        Gate::authorize('manage-webinars');

        $webinar = $action->handle($request->user(), $request->validated());

        return ApiResponse::created($this->authored($webinar));
    }

    /** The words and the places. The TIME moves through the session endpoint. */
    public function update(StoreWebinarRequest $request, Webinar $webinar, UpdateWebinar $action): JsonResponse
    {
        Gate::authorize('manage-webinars');

        return ApiResponse::ok($this->authored($action->handle($webinar, $request->validated())));
    }

    /**
     * Publish, unpublish, cancel, revive — one endpoint, because one Action
     * owns the legal moves and `available_actions` says which are open.
     */
    public function status(Request $request, Webinar $webinar, ChangeWebinarStatus $action): JsonResponse
    {
        Gate::authorize('manage-webinars');

        $validated = $request->validate([
            'status' => ['required', Rule::enum(WebinarStatus::class)],
        ]);

        return ApiResponse::ok($this->authored($action->handle(
            $webinar,
            WebinarStatus::from((string) $validated['status']),
            $request->user(),
        )));
    }

    /** 409 `webinar_in_use` once anybody has registered — cancel it instead. */
    public function destroy(Webinar $webinar, DeleteWebinar $action): JsonResponse
    {
        Gate::authorize('manage-webinars');

        $action->handle($webinar);

        return ApiResponse::noContent();
    }

    /**
     * The shape an author reads back: what it is, and what they may do next.
     *
     * `isRegistered` is false rather than looked up — somebody scheduling an
     * event is not registering for it, and the authoring screens have no
     * button that would care.
     */
    private function authored(Webinar $webinar): WebinarResource
    {
        return new WebinarResource(
            $webinar->fresh()->load('session')->loadCount('registrations'),
            isRegistered: false,
            canManage: true,
        );
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
