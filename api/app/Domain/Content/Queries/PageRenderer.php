<?php

declare(strict_types=1);

namespace App\Domain\Content\Queries;

use App\Domain\Catalog\Models\Course;
use App\Domain\Content\Enums\BlockType;
use App\Domain\Content\Models\Post;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use App\Http\Resources\Catalog\CourseListResource;
use App\Http\Resources\Content\PostListResource;
use App\Http\Resources\Live\WebinarResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A page's blocks with what they POINT AT resolved, for a reader.
 *
 * Each block keeps its `props` and gains `data` where it references something:
 * the course cards, the image's address, the latest posts, the upcoming events.
 *
 * Resolved with the PUBLIC scopes, so the query is the boundary
 * (ROLES_PERMISSIONS §6a): a course that went back to draft, a post not yet out
 * and a cancelled webinar are simply not in the result set, and a page built
 * last month never shows a stranger something that is no longer public.
 *
 * ONE query per block TYPE, never per block — a page with four course grids
 * loads its courses once.
 */
final class PageRenderer
{
    /**
     * @param  list<array{id: string, type: string, props: array<string, mixed>}>  $blocks
     * @return list<array<string, mixed>>
     */
    public function render(array $blocks, Request $request): array
    {
        $ofType = fn (BlockType $type): Collection => collect($blocks)->where('type', $type->value)->values();

        $courses = $this->courses($ofType(BlockType::Courses));
        $images = $this->images($ofType(BlockType::Image));
        $posts = $this->posts($this->largestLimit($ofType(BlockType::Posts)));
        $webinars = $this->webinars($this->largestLimit($ofType(BlockType::Webinars)));

        return array_map(function (array $block) use ($courses, $images, $posts, $webinars, $request): array {
            $type = BlockType::tryFrom($block['type']);
            $props = $block['props'];

            $data = match ($type) {
                BlockType::Courses => collect(is_array($props['course_ids'] ?? null) ? $props['course_ids'] : [])
                    // The author's order, and only what is still live.
                    ->map(fn (mixed $uuid) => is_string($uuid) ? $courses->get($uuid) : null)
                    ->filter()
                    ->map(fn (Course $course): array => (new CourseListResource($course))->resolve($request))
                    ->values()
                    ->all(),
                BlockType::Image => [
                    'url' => $images->get(is_numeric($props['media_ref'] ?? null) ? (int) $props['media_ref'] : 0)?->publicUrl(),
                ],
                BlockType::Posts => $posts
                    ->take(is_numeric($props['limit'] ?? null) ? (int) $props['limit'] : 3)
                    ->map(fn (Post $post): array => (new PostListResource($post))->resolve($request))
                    ->values()
                    ->all(),
                BlockType::Webinars => $webinars
                    ->take(is_numeric($props['limit'] ?? null) ? (int) $props['limit'] : 3)
                    // A stranger holds no place, manages nothing, cancels nothing.
                    ->map(fn (Webinar $webinar): array => (new WebinarResource($webinar, false, false, false))->resolve($request))
                    ->values()
                    ->all(),
                default => null,
            };

            return $data === null ? $block : $block + ['data' => $data];
        }, $blocks);
    }

    /**
     * @param  Collection<int, array{id: string, type: string, props: array<string, mixed>}>  $blocks
     * @return Collection<string, Course>
     */
    private function courses(Collection $blocks): Collection
    {
        $uuids = $blocks
            ->flatMap(fn (array $block): array => is_array($block['props']['course_ids'] ?? null) ? $block['props']['course_ids'] : [])
            ->filter(fn (mixed $uuid): bool => is_string($uuid))
            ->unique()
            ->values();

        if ($uuids->isEmpty()) {
            return collect();
        }

        return Course::query()
            ->live()
            ->whereIn('uuid', $uuids->all())
            // The catalogue card's own eager loads (`CourseCatalogQuery`).
            ->with(['category', 'owner', 'thumbnail', 'product.prices'])
            ->get()
            ->keyBy('uuid');
    }

    /**
     * @param  Collection<int, array{id: string, type: string, props: array<string, mixed>}>  $blocks
     * @return Collection<int, Media>
     */
    private function images(Collection $blocks): Collection
    {
        $ids = $blocks
            ->map(fn (array $block): int => is_numeric($block['props']['media_ref'] ?? null) ? (int) $block['props']['media_ref'] : 0)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Media::query()
            ->whereIn('id', $ids->all())
            // Public covers only: a private file's reference never becomes a URL.
            ->where('collection', MediaCollection::CourseThumbnail->value)
            ->get()
            ->keyBy('id');
    }

    /** @param  Collection<int, array{id: string, type: string, props: array<string, mixed>}>  $blocks */
    private function largestLimit(Collection $blocks): int
    {
        return (int) $blocks->max(fn (array $block): int => is_numeric($block['props']['limit'] ?? null) ? (int) $block['props']['limit'] : 0);
    }

    /** @return Collection<int, Post> */
    private function posts(int $limit): Collection
    {
        if ($limit === 0) {
            return collect();
        }

        return Post::query()
            ->published()
            ->with(['cover', 'author'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Webinar> */
    private function webinars(int $limit): Collection
    {
        if ($limit === 0) {
            return collect();
        }

        return Webinar::query()
            ->published()
            // Both tables are in the academy's schema, so a subquery is safe here.
            ->whereIn('live_session_id', LiveSession::query()->upcoming()->select('id'))
            ->with(['session', 'product.prices'])
            ->withCount([
                'registrations as registered_count' => fn (Builder $query) => $query
                    ->where('status', WebinarRegistration::STATUS_REGISTERED),
            ])
            ->get()
            // Soonest first. Sorted here rather than joined: the published
            // events of one academy are a short list.
            ->sortBy(fn (Webinar $webinar) => $webinar->session?->starts_at?->getTimestamp() ?? PHP_INT_MAX)
            ->take($limit)
            ->values();
    }
}
