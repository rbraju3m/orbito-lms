<?php

declare(strict_types=1);

namespace App\Domain\Certification\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Certification\Enums\CertificateStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use Carbon\CarbonInterface;
use Database\Factories\Certification\CertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One certificate. Immutable except for revocation and its rendered PDF.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property int|null $template_id
 * @property int $user_id
 * @property int $course_id
 * @property int $enrollment_id
 * @property CertificateStatus $status
 * @property CarbonInterface $issued_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $pdf_media_id
 * @property array<string, mixed> $snapshot
 * @property string $verification_token
 *
 * Relations that can genuinely be null, which the BelongsTo generics cannot
 * express: `template_id` and `pdf_media_id` are nullable columns, and
 * `user_id` points across the schema boundary at a table with no foreign key
 * (§ Multi-tenancy), so the row it names can be gone. `course` and `enrollment` are NOT
 * listed — both cascade on delete, so a certificate cannot outlive them.
 * @property-read CertificateTemplate|null $template
 * @property-read Media|null $pdf
 * @property-read User|null $user
 */
final class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    protected $fillable = [
        'number', 'template_id', 'user_id', 'course_id', 'enrollment_id',
        'issued_at', 'expires_at', 'status', 'revoked_at', 'revoked_reason',
        'pdf_media_id', 'snapshot', 'verification_token',
    ];

    /**
     * Hidden from every array cast, because a Resource is not the only way a
     * model reaches a response — a `dd()` in a controller or a queued job's
     * failure payload will serialise it too. The token is a credential.
     *
     * @var list<string>
     */
    protected $hidden = ['verification_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $certificate): void {
            $certificate->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** Central users table — no FK crosses the schema boundary (§ Multi-tenancy). */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CertificateTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'pdf_media_id');
    }

    /**
     * Expired is NOT revoked.
     *
     * A lapsed certificate was genuinely earned, and the verification page has
     * to say so — "issued, then expired" rather than "invalid", which would
     * read as a forgery.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Currently valid: issued, not revoked, not expired. */
    public function isValid(): bool
    {
        return $this->status->isValid() && ! $this->hasExpired();
    }
}
