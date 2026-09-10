<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\DownloadSource;
use App\Support\Database\LivesInTenantSchema;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The right to fetch one download — the entitlement, never a delivery.
 *
 * Created ONLY by `GrantDownload`, which catches the unique violation rather
 * than checking first: a double click on "Get it free" must not be the race
 * that decides whether somebody owns a thing twice.
 *
 * @property int $id
 * @property int $download_id
 * @property int $user_id
 * @property DownloadSource $source
 * @property int|null $order_id
 * @property CarbonInterface $granted_at
 * @property CarbonInterface|null $revoked_at
 */
final class DownloadGrant extends Model
{
    use LivesInTenantSchema;

    protected $fillable = ['download_id', 'user_id', 'source', 'order_id', 'granted_at', 'revoked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => DownloadSource::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Download, $this> */
    public function download(): BelongsTo
    {
        return $this->belongsTo(Download::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** @param  Builder<DownloadGrant>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
