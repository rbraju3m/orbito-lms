<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Domain\Content\Actions\CreatePost;
use App\Domain\Content\Actions\DeletePost;
use App\Domain\Content\Actions\UpdatePost;
use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Models\Post;
use App\Http\Requests\Content\StorePostRequest;
use App\Http\Requests\Content\UpdatePostRequest;
use App\Http\Resources\Content\PostListResource;
use App\Http\Resources\Content\PostResource;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The academy's blog, for the people who write it (`post.manage`). See docs/BLOG.md. */
final class PostController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Post::class);

        $status = PostStatus::tryFrom((string) $request->query('status', ''));
        $search = addcslashes(trim((string) $request->query('q', '')), '%_\\');

        $posts = Post::query()
            ->with(['cover', 'author'])
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%'))
            ->orderByDesc('updated_at')
            // Second precision: without a tiebreak a row can sit on two pages.
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(
            PostListResource::collection($posts->through(fn (Post $post) => new PostListResource($post, canManage: true)))
                ->additional(['meta' => ['can_manage' => true]]),
        );
    }

    public function store(StorePostRequest $request, CreatePost $action): JsonResponse
    {
        Gate::authorize('create', Post::class);

        $post = $action->handle($request->user(), $request->validated());

        return ApiResponse::created(new PostResource($post->load(['cover', 'author']), canManage: true));
    }

    public function show(Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        return ApiResponse::ok(new PostResource($post->load(['cover', 'author']), canManage: true));
    }

    public function update(UpdatePostRequest $request, Post $post, UpdatePost $action): JsonResponse
    {
        Gate::authorize('update', $post);

        $post = $action->handle($post, $request->validated());

        return ApiResponse::ok(new PostResource($post->load(['cover', 'author']), canManage: true));
    }

    public function destroy(Post $post, DeletePost $action): JsonResponse
    {
        Gate::authorize('delete', $post);

        $action->handle($post);

        return ApiResponse::noContent();
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page'));
    }
}
