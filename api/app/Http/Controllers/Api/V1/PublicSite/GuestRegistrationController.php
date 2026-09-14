<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Live\Actions\CancelGuestPlace;
use App\Domain\Live\Actions\ConfirmGuestRegistration;
use App\Domain\Live\Actions\FindGuestPlace;
use App\Domain\Live\Actions\JoinAsGuest;
use App\Domain\Live\Actions\RequestGuestRegistration;
use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Http\Requests\Live\GuestTokenRequest;
use App\Http\Requests\Live\RequestGuestRegistrationRequest;
use App\Http\Resources\Live\WebinarResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A place at a free webinar for somebody with no account — the product's
 * second anonymous write (docs/GUEST_REGISTRATION.md).
 *
 * Two halves, and the split is the whole design:
 *
 *  - ASKING (`store`) is a stranger's request. It writes nothing, mails the
 *    address, and answers 202 whatever happened — a new address, one already
 *    holding a place, one over its mail cap, a trap.
 *  - Everything else takes a TOKEN from that mail. Holding one proves somebody
 *    can read the mailbox, so these endpoints may answer plainly: the room is
 *    full, the link expired, the meeting has not opened yet.
 */
final class GuestRegistrationController
{
    public function store(
        RequestGuestRegistrationRequest $request,
        string $academy,
        string $slug,
        RequestGuestRegistration $action,
    ): JsonResponse {
        $webinar = Webinar::query()->published()->where('slug', $slug)->first()
            ?? throw new NotFoundHttpException;

        // Whether a place is sold is on the public page already, so saying so
        // is no oracle — and buying needs an account.
        if ($webinar->is_paid) {
            throw LiveSessionRejected::webinarGuestNotAllowed();
        }

        if (! $request->isTrap()) {
            $action->handle($academy, $webinar, $request->email(), $request->guestName());
        }

        return ApiResponse::accepted(['received' => true]);
    }

    public function confirm(GuestTokenRequest $request, string $academy, ConfirmGuestRegistration $action): JsonResponse
    {
        ['registration' => $registration, 'token' => $token] = $action->handle($academy, $request->token());

        return ApiResponse::created($this->place($request, $registration, $token));
    }

    public function show(GuestTokenRequest $request, string $academy, FindGuestPlace $find): JsonResponse
    {
        return ApiResponse::ok($this->place($request, $find->handle($academy, $request->token()), $request->token()));
    }

    public function cancel(
        GuestTokenRequest $request,
        string $academy,
        FindGuestPlace $find,
        CancelGuestPlace $cancel,
    ): JsonResponse {
        $registration = $cancel->handle($find->handle($academy, $request->token()));

        return ApiResponse::ok($this->place($request, $registration, $request->token()));
    }

    public function join(GuestTokenRequest $request, string $academy, FindGuestPlace $find, JoinAsGuest $join): JsonResponse
    {
        return ApiResponse::ok(['join_url' => $join->handle($find->handle($academy, $request->token()))]);
    }

    /**
     * The manage page's whole answer: the place, what they may DO with it —
     * from the same rules `CancelGuestPlace` and `JoinAsGuest` enforce, so a
     * button that would refuse is never drawn — and the event, rendered by the
     * same resource the public page reads.
     *
     * @return array<string, mixed>
     */
    private function place(Request $request, WebinarRegistration $registration, string $token): array
    {
        $registration->loadMissing(['webinar.session', 'webinar.product.prices']);

        /** @var Webinar $webinar */
        $webinar = $registration->webinar;
        $live = $registration->status === WebinarRegistration::STATUS_REGISTERED;
        $canCancel = $live && $registration->order_id === null;

        return [
            'status' => $registration->status,
            'email' => $registration->email,
            'name' => $registration->name,
            'can_cancel' => $canCancel,
            'can_join' => $live
                && $webinar->status->isOpen()
                && $webinar->session?->isJoinable() === true
                && $webinar->session->join_url !== null,
            // Echoed so the confirm page can hand the SPA the manage link.
            'token' => $token,
            'webinar' => (new WebinarResource($webinar, isRegistered: $live, canManage: false, canCancel: $canCancel))
                ->resolve($request),
        ];
    }
}
