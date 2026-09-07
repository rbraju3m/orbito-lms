<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Support\PublishChecklist;
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
}
