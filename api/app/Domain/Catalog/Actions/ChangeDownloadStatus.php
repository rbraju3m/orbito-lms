<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\DownloadStatus;
use App\Domain\Catalog\Events\DownloadStatusChanged;
use App\Domain\Catalog\Exceptions\DownloadNotPublishable;
use App\Domain\Catalog\Exceptions\DownloadTransitionRejected;
use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Support\DownloadPublishChecklist;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/** Every lifecycle move for a download. One place owns the transitions (§10). */
final class ChangeDownloadStatus
{
    public function __construct(private readonly DownloadPublishChecklist $checklist) {}

    public function handle(Download $download, DownloadStatus $target, ?User $actor = null): Download
    {
        $from = $download->status;

        if ($from === $target) {
            return $download;
        }

        if (! $from->canTransitionTo($target)) {
            throw DownloadTransitionRejected::illegal($from, $target);
        }

        if ($target === DownloadStatus::Published) {
            $failures = $this->checklist->blockingFailures($download);

            if ($failures !== []) {
                throw new DownloadNotPublishable($failures);
            }
        }

        DB::transaction(function () use ($download, $target): void {
            $download->status = $target;

            // Set once: the download's birthday, not its last edit.
            if ($target === DownloadStatus::Published) {
                $download->published_at ??= now();
            }

            $download->save();
        });

        DownloadStatusChanged::dispatch($download, $from, $target, $actor?->id);

        return $download->refresh();
    }
}
