<?php

declare(strict_types=1);

namespace App\Domain\Certification\Models;

use App\Domain\Certification\Enums\TemplateOrientation;
use App\Domain\Media\Models\Media;
use Database\Factories\Certification\CertificateTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * How a certificate looks.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property TemplateOrientation $orientation
 * @property int|null $background_media_id
 * @property array<string, mixed> $layout
 * @property bool $is_default
 * @property bool $is_active
 */
final class CertificateTemplate extends Model
{
    /** @use HasFactory<CertificateTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'orientation', 'background_media_id', 'layout', 'is_default', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'orientation' => TemplateOrientation::class,
            'layout' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $template): void {
            $template->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Media, $this> */
    public function background(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'background_media_id');
    }

    /**
     * The shape `layout` is expected to hold, and the fallback when a template
     * has none. Declared here rather than in the renderer so the editor and
     * the renderer cannot disagree about what a template contains.
     *
     * @return array<string, mixed>
     */
    public static function defaultLayout(): array
    {
        return [
            'heading' => 'Certificate of Completion',
            'body' => 'This is to certify that {learner_name} has successfully completed {course_title}.',
            'signature_name' => '',
            'signature_title' => '',
            'accent_colour' => '#1c7ed6',
            'show_score' => false,
            'show_qr' => true,
        ];
    }
}
