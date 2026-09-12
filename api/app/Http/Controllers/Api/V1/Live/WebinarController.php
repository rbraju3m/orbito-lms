<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\Live\Actions\ChangeWebinarStatus;
use App\Domain\Live\Actions\CreateWebinar;
use App\Domain\Live\Actions\DeleteWebinar;
use App\Domain\Live\Actions\RegisterForWebinar;
use App\Domain\Live\Actions\UpdateWebinar;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Providers\LiveProviderFactory;
use App\Http\Requests\Live\StoreWebinarRequest;
use App\Http\Resources\Live\WebinarResource;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Standalone live events.
 *
 * The MEMBER's view: what they hold a place at, and what they may do about
 * it. A stranger reads the same events through `PublicSite\WebinarController`
 * — published ones only, with no viewer flags — because reading is public and
 * REGISTERING is not (see `Webinar`'s docblock for why a guest place is a
 * bigger feature than a form).
 */
final class WebinarController
{
    public function index(Request $request, LiveProviderFactory $providers): JsonResponse
    {
        $canManage = Gate::allows('manage-webinars');

        $webinars = Webinar::query()
            ->with(['session', 'product.prices'])
            ->withCount($this->counts())
            // Drafts only for the people who can publish them.
            ->unless($canManage, fn ($query) => $query->published())
            ->orderBy('created_at', 'desc')
            ->paginate($this->perPage($request));

        $held = $this->heldPlaces($request, $webinars->pluck('id')->all());

        return ApiResponse::ok(
            WebinarResource::collection($webinars->through(
                fn (Webinar $webinar) => new WebinarResource(
                    $webinar,
                    array_key_exists($webinar->id, $held),
                    $canManage,
                    // A bought place is given up by a refund, never by this
                    // button: the way back in is the one thing they cannot do.
                    canCancel: ($held[$webinar->id] ?? true) === false,
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

        $held = $this->heldPlaces($request, [$webinar->id]);

        return ApiResponse::ok(new WebinarResource(
            $webinar->load(['session', 'product.prices'])->loadCount($this->counts()),
            $held !== [],
            $canManage,
            canCancel: ($held[$webinar->id] ?? true) === false,
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
            $webinar->fresh()->load(['session', 'product.prices'])->loadCount($this->counts()),
            isRegistered: false,
            canManage: true,
        );
    }

    public function register(Request $request, Webinar $webinar, RegisterForWebinar $action): JsonResponse
    {
        $action->handle($request->user(), $webinar);

        return ApiResponse::created(new WebinarResource(
            $webinar->fresh()->load(['session', 'product.prices'])->loadCount($this->counts()),
            isRegistered: true,
            // Reached only by the FREE path — a paid place is held by
            // `GrantOrderAccess`, never by this endpoint.
            canCancel: true,
        ));
    }

    /**
     * Cancelling frees the place rather than deleting the record of it.
     *
     * A place that was BOUGHT cannot be given up here: re-registering at a
     * paid event 423s, so allowing it would let one click lock somebody out of
     * something they paid for. That is a refund, and a refund cancels the
     * registration itself (`RevokeOrderAccess`).
     */
    public function cancel(Request $request, Webinar $webinar): JsonResponse
    {
        $held = WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->where('email', $request->user()->email)
            ->live()
            ->first();

        if ($held !== null) {
            if ($held->order_id !== null) {
                throw LiveSessionRejected::webinarPlacePurchased();
            }

            $held->forceFill(['status' => WebinarRegistration::STATUS_CANCELLED])->save();
        }

        return ApiResponse::ok(new WebinarResource(
            $webinar->fresh()->load(['session', 'product.prices'])->loadCount($this->counts()),
            isRegistered: false,
        ));
    }

    /**
     * TWO counts, because they answer different questions.
     *
     * `registrations_count` is every record ever made, which is what makes a
     * webinar undeletable — a cancelled registration is still somebody the
     * academy told about an event. `registered_count` is who is actually
     * coming, which is what a place is subtracted from. Loading it here is
     * also what keeps `placesRemaining()` from being one COUNT per row.
     *
     * @return array<int|string, Closure|string>
     */
    private function counts(): array
    {
        return [
            'registrations',
            'registrations as registered_count' => fn ($query) => $query
                ->where('status', WebinarRegistration::STATUS_REGISTERED),
        ];
    }

    /**
     * The caller's own places, by webinar id, and whether each was BOUGHT.
     *
     * The second half is what the resource renders as `can_cancel`, from the
     * same fact the cancel endpoint refuses on: a bought place cannot be given
     * up here, because re-registering at a paid event 423s. A button that would
     * 409 is a bug, not a permission check (§ Patterns established in Phase 8).
     *
     * @param  list<int>  $webinarIds
     * @return array<int, bool> webinar id => was it paid for
     */
    private function heldPlaces(Request $request, array $webinarIds): array
    {
        if ($webinarIds === []) {
            return [];
        }

        return WebinarRegistration::query()
            ->whereIn('webinar_id', $webinarIds)
            // Matched on EMAIL, like the unique key, so a registration made
            // before somebody had an account still counts as theirs.
            ->where('email', $request->user()->email)
            ->live()
            ->get(['webinar_id', 'order_id'])
            ->mapWithKeys(fn (WebinarRegistration $held): array => [
                (int) $held->webinar_id => $held->order_id !== null,
            ])
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
