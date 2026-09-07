<?php

declare(strict_types=1);

namespace Database\Factories\Media;

use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Enums\MediaDisk;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
final class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'owner_id' => User::factory(),
            'disk' => MediaDisk::Public,
            'path' => 'course_thumbnail/2026/09/'.Str::uuid7()->toString().'.jpg',
            'collection' => MediaCollection::CourseThumbnail->value,
            'original_name' => 'thumbnail.jpg',
            'mime' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 128_000,
            'width' => 1280,
            'height' => 720,
            'duration_seconds' => null,
            'checksum' => null,
            'status' => MediaStatus::Ready,
            'attachable_type' => null,
            'attachable_id' => null,
            'meta' => null,
        ];
    }

    public function forCollection(MediaCollection $collection): static
    {
        return $this->state(fn () => [
            'collection' => $collection->value,
            'disk' => $collection->disk(),
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }
}
