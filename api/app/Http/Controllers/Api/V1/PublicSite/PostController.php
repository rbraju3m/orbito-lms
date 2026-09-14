<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicSite;

use App\Domain\Content\Models\Post;
use App\Http\Resources\Content\PostListResource;
use App\Http\Resources\Content\PostResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The academy's blog, to a stranger.
 *
 * What is visible is decided by the QUERY (`Post::published()`): a draft and a
 * scheduled post are not refused, they are not in the result set — so a draft's
 * slug is the same 404 as a slug that never existed (ROLES_PERMISSIONS §6a).
 * The resources are built with no authoring flag, so status, scheduling and the
 * editor's keys are absent rather than false.
 */
final class PostController
{
    public function index(Request $request): JsonResponse
    {
        $posts = Post::query()
            ->published()
            ->with(['cover', 'author'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(min(
                (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
                (int) config('orbito.pagination.max_per_page'),
            ));

        return ApiResponse::ok(PostListResource::collection($posts->through(fn (Post $post) => new PostListResource($post))));
    }

    public function show(Request $request, string $academy, string $slug): JsonResponse
    {
        $post = Post::query()
            ->published()
            // By SLUG: this is the address an academy shares.
            ->where('slug', $slug)
            ->with(['cover', 'author'])
            ->first();

        if ($post === null) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::ok(new PostResource($post));
    }
}
