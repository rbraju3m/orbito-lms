<?php

declare(strict_types=1);

namespace App\Domain\Media\Support;

use App\Domain\Assessment\Models\AssignmentSubmissionFile;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Exceptions\UploadQuotaExceeded;
use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How much a person may hold in files they uploaded and have not USED yet.
 *
 * Only the collections anybody may write into count
 * (`MediaCollection::hasPersonalQuota()`): an avatar, and the work a learner
 * hands in. Authoring collections are gated by authoring permissions, and
 * what an academy's own staff store is the plan's storage figure, not this.
 *
 * A file stops counting once it is handed in. What a learner submits is
 * already bounded by rules somebody chose — the assignment's file count, size
 * cap and attempts — and it lands in front of whoever grades it. What nothing
 * bounded was the other kind: uploaded, never handed in, repeated. That is
 * the quota. It also means the person over it can always do something about
 * it — hand the files in, or remove them — which a cap on everything they
 * ever submitted could not offer (§ Patterns established in Phase 16: cap the
 * party who can DO something about it).
 *
 * Media asks Assessment directly, as `DeleteMedia` asks Catalog: a limit must
 * answer before the bytes are written, and an event cannot say no. A new
 * place that CONSUMES files from these collections belongs in `unused()`, or
 * learners are charged for files they have used.
 */
final class UploadQuota
{
    public function limitBytes(): int
    {
        return (int) config('orbito.media.unattached_quota_bytes');
    }

    public function usedBytes(User $owner): int
    {
        return (int) $this->unused($owner)->sum('size_bytes');
    }

    /**
     * Refuses a file that would take its owner past the quota.
     *
     * Not atomic, and it does not need to be: two uploads racing can both
     * pass and land the owner one file over — at most one collection cap per
     * concurrent request, and the `uploads` rate limit bounds how many there
     * are. This bounds abuse; it is not a promise anybody paid for, and a lock
     * held across a 25 MB write would cost more than the overshoot. Do not copy
     * this reasoning to a seat limit.
     */
    public function ensureRoomFor(User $owner, MediaCollection $collection, int $bytes): void
    {
        if (! $collection->hasPersonalQuota()) {
            return;
        }

        $used = $this->usedBytes($owner);
        $limit = $this->limitBytes();

        if ($used + $bytes > $limit) {
            throw UploadQuotaExceeded::over($used, $limit, $bytes);
        }
    }

    /**
     * Their files in the quota'd collections that nothing references.
     *
     * Both `media_id` columns below carry a foreign key, so MySQL has already
     * indexed them, and (owner_id, collection) leads the media table's own
     * index. Soft-deleted files are excluded by the model's scope — their
     * bytes went first (`DeleteMedia`).
     *
     * @return Builder<Media>
     */
    private function unused(User $owner): Builder
    {
        return Media::query()
            ->where('owner_id', $owner->id)
            ->whereIn('collection', MediaCollection::withPersonalQuota())
            // Handed in.
            ->whereNotIn('id', AssignmentSubmissionFile::query()->select('media_id'))
            // On an assignment's brief — `UpdateAssignmentRequest` accepts
            // submission files there.
            ->whereNotIn('id', DB::table('assignment_attachments')->select('media_id'));
    }
}
