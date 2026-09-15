<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Domain\Content\Actions\CreatePage;
use App\Domain\Content\Actions\DeletePage;
use App\Domain\Content\Actions\SavePageBlocks;
use App\Domain\Content\Actions\UpdatePage;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\Page;
use App\Domain\Content\Queries\PageRenderer;
use App\Http\Requests\Content\SavePageBlocksRequest;
use App\Http\Requests\Content\StorePageRequest;
use App\Http\Requests\Content\UpdatePageRequest;
use App\Http\Resources\Content\PageListResource;
use App\Http\Resources\Content\PageResource;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The academy's pages, for the people who build them (`page.manage`). See docs/PAGES.md. */
final class PageController
{
    public function __construct(private readonly PageRenderer $renderer) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Page::class);

        $status = PageStatus::tryFrom((string) $request->query('status', ''));

        $pages = Page::query()
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page')));

        return ApiResponse::ok(PageListResource::collection($pages)->additional(['meta' => ['can_manage' => true]]));
    }

    public function store(StorePageRequest $request, CreatePage $action): JsonResponse
    {
        Gate::authorize('create', Page::class);

        return ApiResponse::created($this->authored($request, $action->handle($request->user(), $request->validated())));
    }

    public function show(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('view', $page);

        return ApiResponse::ok($this->authored($request, $page));
    }

    public function update(UpdatePageRequest $request, Page $page, UpdatePage $action): JsonResponse
    {
        Gate::authorize('update', $page);

        return ApiResponse::ok($this->authored($request, $action->handle($page, $request->validated())));
    }

    /** The whole block list, replaced. */
    public function blocks(SavePageBlocksRequest $request, Page $page, SavePageBlocks $action): JsonResponse
    {
        Gate::authorize('update', $page);

        return ApiResponse::ok($this->authored($request, $action->handle($page, $request->blocks())));
    }

    public function destroy(Page $page, DeletePage $action): JsonResponse
    {
        Gate::authorize('delete', $page);

        $action->handle($page);

        return ApiResponse::noContent();
    }

    private function authored(Request $request, Page $page): PageResource
    {
        return new PageResource($page, $this->renderer->render($page->blockList(), $request), canManage: true);
    }
}
