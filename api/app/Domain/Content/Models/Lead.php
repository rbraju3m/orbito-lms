<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\LeadSource;
use App\Domain\Content\Enums\LeadStatus;
use Carbon\CarbonInterface;
use Database\Factories\Content\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Somebody with no account who asked an academy to keep in touch.
 *
 * Written by a stranger, read by the academy's staff, and never by the person
 * it describes — there is no account to show it to. See docs/LEADS.md for
 * the abuse story that decides its shape.
 *
 * @property int $id
 * @property string $uuid
 * @property string $email
 * @property string|null $name
 * @property LeadStatus $status
 * @property LeadSource $source
 * @property int|null $source_id
 * @property string|null $source_title
 * @property string $consent_text
 * @property CarbonInterface $consented_at
 * @property int $submissions_count
 * @property CarbonInterface $last_submitted_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'email', 'name', 'status', 'source', 'source_id', 'source_title',
        'consent_text', 'consented_at', 'submissions_count', 'last_submitted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'source_id' => 'integer',
            'consented_at' => 'datetime',
            'submissions_count' => 'integer',
            'last_submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $lead): void {
            $lead->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * One spelling per address, so the unique index IS the dedupe. Lower-cased
     * whole: the local part is case-sensitive in the RFC and in no mailbox
     * anybody uses, and two rows for one person is the worse failure.
     */
    public static function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
