<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Http\Resources\Live\WebinarResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Published webinars, to a stranger.
 *
 * This is the surface `Webinar`'s docblock has been waiting for since P15:
 * a webinar is the one live format not gated on buying a course, and until
 * there was somewhere to read about one without an account, "open evening"
 * meant open to members.
 *
 * REGISTERING is not here yet, and the omission is deliberate: a place held
 * by an email with no account cannot be told anything when the event is
 * called off (delivery is to a central account), so the guest path needs a
 * mail-only delivery to go with it. Reading about one comes first.
 *
 * The resource is constructed with every viewer flag false — a stranger holds
 * no place, may manage nothing and may cancel nothing — rather than left to
 * default, because `canCancel` defaults to TRUE for the member case.
 */
final class WebinarController
{
    public function index(Request $request): JsonResponse
    {
        $webinars = Webinar::query()
            ->published()
            ->with(['session', 'product.prices'])
            ->withCount([
                'registrations as registered_count' => fn ($query) => $query
                    ->where('status', WebinarRegistration::STATUS_REGISTERED),
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(min(
                (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
                (int) config('orbito.pagination.max_per_page'),
            ));

        return ApiResponse::ok(WebinarResource::collection($webinars->through(
            fn (Webinar $webinar) => new WebinarResource($webinar, false, false, false),
        )));
    }

    public function show(Request $request, string $academy, string $slug): JsonResponse
    {
        $webinar = Webinar::query()
            ->published()
            // By SLUG, not uuid: this is a link an academy prints.
            ->where('slug', $slug)
            ->with(['session', 'product.prices'])
            ->withCount([
                'registrations as registered_count' => fn ($query) => $query
                    ->where('status', WebinarRegistration::STATUS_REGISTERED),
            ])
            ->first();

        if ($webinar === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(new WebinarResource($webinar, false, false, false));
    }
}
