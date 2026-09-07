<?php

declare(strict_types=1);

namespace App\Domain\Media\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaDisk;
use App\Domain\Media\Enums\MediaStatus;
use Database\Factories\Media\MediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $owner_id
 * @property MediaDisk $disk
 * @property string $path
 * @property string $collection
 * @property string $original_name
 * @property string $mime
 * @property string $extension
 * @property int $size_bytes
 * @property MediaStatus $status
 */
final class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'owner_id', 'disk', 'path', 'collection', 'original_name', 'mime', 'extension',
        'size_bytes', 'width', 'height', 'duration_seconds', 'checksum', 'status',
        'attachable_type', 'attachable_id', 'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'disk' => MediaDisk::class,
            'status' => MediaStatus::class,
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $media): void {
            $media->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<MediaVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function isPublic(): bool
    {
        return ! $this->disk->isPrivate();
    }

    /**
     * A permanent URL for public files; null for private ones, which must go
     * through a signed, access-checked URL instead (ADR-09).
     */
    public function publicUrl(): ?string
    {
        return $this->isPublic() ? Storage::disk('public')->url($this->path) : null;
    }
}
