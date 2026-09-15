<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Domain\Content\Actions\ChangePageStatus;
use App\Domain\Content\Actions\SetHomePage;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Queries\PageRenderer;
use App\Http\Resources\Content\PageResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** A page's lifecycle, and whether it is the front page — sub-resources, never form fields. */
final class PageStatusController
{
    public function __construct(private readonly PageRenderer $renderer) {}

    public function publish(Request $request, Page $page, ChangePageStatus $action): JsonResponse
    {
        Gate::authorize('publish', $page);

        return $this->respond($request, $action->publish($page));
    }

    public function unpublish(Request $request, Page $page, ChangePageStatus $action): JsonResponse
    {
        Gate::authorize('publish', $page);

        return $this->respond($request, $action->unpublish($page));
    }

    public function makeHome(Request $request, Page $page, SetHomePage $action): JsonResponse
    {
        Gate::authorize('publish', $page);

        return $this->respond($request, $action->handle($page));
    }

    public function clearHome(Request $request, Page $page, SetHomePage $action): JsonResponse
    {
        Gate::authorize('publish', $page);

        return $this->respond($request, $action->clear($page));
    }

    private function respond(Request $request, Page $page): JsonResponse
    {
        return ApiResponse::ok(new PageResource($page, $this->renderer->render($page->blockList(), $request), canManage: true));
    }
}
