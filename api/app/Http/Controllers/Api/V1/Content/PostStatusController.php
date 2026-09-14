<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Domain\Content\Actions\ChangePostStatus;
use App\Domain\Content\Models\Post;
use App\Http\Requests\Content\PublishPostRequest;
use App\Http\Resources\Content\PostResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** A post's lifecycle as sub-resources, never a field on the edit form. */
final class PostStatusController
{
    public function __construct(private readonly ChangePostStatus $action) {}

    /** Now, or at `published_at` — a future time schedules it. */
    public function publish(PublishPostRequest $request, Post $post): JsonResponse
    {
        Gate::authorize('publish', $post);

        $post = $this->action->publish($post, $request->publishAt());

        return ApiResponse::ok(new PostResource($post->load(['cover', 'author']), canManage: true));
    }

    public function unpublish(Post $post): JsonResponse
    {
        Gate::authorize('publish', $post);

        $post = $this->action->unpublish($post);

        return ApiResponse::ok(new PostResource($post->load(['cover', 'author']), canManage: true));
    }
}
