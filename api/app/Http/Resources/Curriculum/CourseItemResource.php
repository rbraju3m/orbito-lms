<?php

declare(strict_types=1);

namespace App\Http\Resources\Curriculum;

use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\Lesson;
use App\Domain\Curriculum\Models\Resource as CurriculumResource;
use App\Domain\Media\Support\MediaUrlGenerator;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin CourseItem
 */
final class CourseItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            // The numeric id is what the reorder endpoint speaks, since the
            // whole tree moves at once and uuids would bloat the payload.
            'ref' => $this->id,
            'section_id' => $this->section_id,
            'position' => $this->position,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'is_preview' => $this->is_preview,
            'is_published' => $this->is_published,
            'is_completable' => $this->type->isCompletable(),
            'duration_seconds' => $this->duration_seconds,
            'updated_at' => $this->updated_at?->toIso8601String(),

            'drip_available_at' => $this->drip_available_at?->toIso8601String(),
            'drip_after_days' => $this->drip_after_days,
            // The numeric ref, matching `ref` above: the builder's item picker
            // speaks in the same id the reorder endpoint does.
            'drip_after_item_id' => $this->drip_after_item_id,

            'content' => $this->whenLoaded('itemable', fn () => $this->itemableContent()),
        ];
    }

    /** @return array<string, mixed>|null */
    private function itemableContent(): ?array
    {
        $itemable = $this->itemable;

        if ($itemable instanceof Lesson) {
            return [
                'content' => $itemable->content,
                'content_format' => $itemable->content_format->value,
                'video_provider' => $itemable->video_provider->value,
                'video_media_id' => $itemable->video_media_id,
                'video_url' => $itemable->video_url,
                'video_duration_seconds' => $itemable->video_duration_seconds,
                'document_media_id' => $itemable->document_media_id,
            ];
        }

        if ($itemable instanceof CurriculumResource) {
            return [
                'description' => $itemable->description,
                'media_id' => $itemable->media_id,
                'external_url' => $itemable->external_url,
                'download_allowed' => $itemable->download_allowed,
                'url' => $itemable->media !== null
                    ? app(MediaUrlGenerator::class)->for($itemable->media)
                    : $itemable->external_url,
            ];
        }

        return null;
    }
}
