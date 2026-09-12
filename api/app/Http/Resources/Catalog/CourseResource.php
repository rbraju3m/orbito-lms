<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Support\PublishChecklist;
use App\Domain\Engagement\Models\WishlistItem;
use App\Domain\Enrollment\Support\PrerequisiteCheck;
use App\Domain\Identity\Models\User;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The full course payload, for the course page and the Studio editor.
 *
 * @mixin Course
 */
final class CourseResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isStaff = $viewer !== null && $viewer->can('update', $this->resource);

        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,

            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'locale' => $this->locale,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'visibility' => $this->visibility->value,
            'completion_mode' => $this->completion_mode->value,
            'pricing_model' => $this->pricing_model->value,

            /*
             * Display only. The figure that CHARGES is re-read at checkout;
             * null means "not buyable now", which is not the same as free.
             */
            'price' => CoursePrice::for(
                $this->resource,
                strtoupper((string) config('orbito.currency.base')),
            ),

            'thumbnail' => $this->whenLoaded('thumbnail', fn () => $this->thumbnail?->publicUrl()),
            'thumbnail_media_id' => $this->thumbnail_media_id,
            'intro_video_media_id' => $this->intro_video_media_id,
            'intro_video_url' => $this->intro_video_url,

            'item_count' => $this->item_count,
            'section_count' => $this->section_count,
            'total_duration_seconds' => $this->total_duration_seconds,
            'enrollment_count' => $this->enrollment_count,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,

            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'category' => $this->whenLoaded(
                'category',
                fn () => $this->category
                    ? CourseCategoryResource::make($this->category)->resolve($request)
                    : null,
            ),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')->values()),

            // Enrolment gates, rendered so the button can explain itself. The
            // same PrerequisiteCheck EnrollInCourse consults, so what the page
            // says and what the server does cannot drift apart.
            ...$this->enrolmentGates($viewer),

            'detail' => $this->whenLoaded('detail', fn () => [
                'objectives' => $this->detail->objectives ?? [],
                'requirements' => $this->detail->requirements ?? [],
                'target_audience' => $this->detail->target_audience ?? [],
                'materials' => $this->detail->materials ?? [],
            ]),

            'instructors' => $this->whenLoaded(
                'instructors',
                fn () => $this->instructors->map(fn ($instructor) => [
                    'id' => $instructor->user?->uuid,
                    'name' => $instructor->user?->name,
                    'headline' => $instructor->user?->headline,
                    'role' => $instructor->role->value,
                    'role_label' => $instructor->role->label(),
                ])->values(),
            ),

            // Settings and the publish checklist are authoring concerns; a
            // visitor browsing the catalogue has no business seeing either.
            'settings' => $this->when($isStaff, fn () => $this->setting?->only([
                'enable_qa', 'enable_reviews', 'enable_notes', 'enable_certificate',
                'max_students', 'enrollment_expires_days', 'drip_mode',
                'retake_allowed', 'reset_progress_allowed', 'video_completion_threshold',
            ])),

            'publish_checklist' => $this->when(
                $isStaff,
                fn () => app(PublishChecklist::class)->evaluate($this->resource),
            ),

            'allowed_transitions' => $this->when(
                $isStaff,
                fn () => array_map(
                    fn ($status) => $status->value,
                    $this->status->allowedTransitions(),
                ),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrolmentGates(?User $viewer): array
    {
        // Strict mode forbids implicit lazy loading, and this resource is
        // rendered from several controllers — load it here rather than relying
        // on every one of them remembering.
        $this->resource->loadMissing('prerequisites');

        $unmet = app(PrerequisiteCheck::class)->unmetFor($viewer, $this->resource);

        return [
            'prerequisites' => $this->prerequisites
                ->map(fn (Course $prerequisite): array => [
                    'id' => $prerequisite->uuid,
                    'ref' => $prerequisite->id,
                    'slug' => $prerequisite->slug,
                    'title' => $prerequisite->title,
                    // Per-course, so the page can tick off the ones they hold
                    // instead of listing every requirement as outstanding.
                    'is_met' => ! $unmet->contains('id', $prerequisite->id),
                ])
                ->values()
                ->all(),

            // null means uncapped, which is not the same as 0 left.
            'seats_remaining' => $this->seatsRemaining(),

            /*
             * Whether this reader has saved it. On the DETAIL resource only —
             * one indexed exists() per page view. The catalogue list must not
             * carry it: that would be a query per card, and the grid's cards
             * are single links with no room for a control anyway.
             *
             * ABSENT for a stranger rather than false, now that the public
             * site renders this same resource with no viewer. `false` invites
             * a wishlist toggle onto a page whose reader has no account to
             * save anything to; missing says there is no such question here.
             */
            'is_wishlisted' => $this->when(
                $viewer !== null,
                fn (): bool => WishlistItem::query()
                    ->where('user_id', $viewer?->id)
                    ->where('course_id', $this->id)
                    ->exists(),
            ),
        ];
    }
}
