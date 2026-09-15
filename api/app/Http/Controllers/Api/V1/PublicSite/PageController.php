<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Content\Models\Page;
use App\Domain\Content\Queries\PageRenderer;
use App\Http\Resources\Content\PageResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The academy's built pages, to a stranger. Published ones only, decided by
 * the QUERY: a draft page is not refused, it is not in the result set, so its
 * address is the same 404 as one that never existed.
 */
final class PageController
{
    public function __construct(private readonly PageRenderer $renderer) {}

    public function show(Request $request, string $academy, string $slug): JsonResponse
    {
        return $this->render($request, Page::query()->published()->where('slug', $slug)->first());
    }

    /**
     * The page the academy chose as its front page, once it is published. A
     * 404 means "none" — and the site then shows its standard front page, so
     * a stranger never lands on nothing.
     */
    public function home(Request $request): JsonResponse
    {
        return $this->render($request, Page::query()->published()->where('home_key', Page::HOME)->first());
    }

    /**
     * The pages linked from the site's header: published and flagged, oldest
     * first. Capped rather than paginated on purpose — a header holds a
     * handful of links, and a hundred would be a menu nobody can use.
     */
    public function navigation(): JsonResponse
    {
        $pages = Page::query()
            ->published()
            ->where('show_in_nav', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(8)
            ->get(['slug', 'title']);

        return ApiResponse::ok($pages->map(fn (Page $page): array => [
            'slug' => $page->slug,
            'title' => $page->title,
        ])->values()->all());
    }

    private function render(Request $request, ?Page $page): JsonResponse
    {
        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(new PageResource($page, $this->renderer->render($page->blockList(), $request)));
    }
}
