<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Actions\DeleteMedia;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Exceptions\MediaInUse;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Support\ByteSize;
use App\Domain\Media\Support\UploadQuota;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Deletes uploads nothing ever used, once their grace period has passed.
 *
 * The submission form keeps what a learner attached only in the page. Leave
 * the page and those files are unreachable — no screen lists them — while
 * they sit on the academy's storage and count against the learner's upload
 * quota. This is what makes that quota fair over years.
 *
 * "Unused" is `UploadQuota::unusedFiles()`, never a second definition: two
 * would disagree about which files are safe to delete. Narrowed to the
 * collections `MediaCollection::sweptWhenUnused()` names — not avatars yet.
 *
 * Through `DeleteMedia`, one file at a time, so the bytes go first, the
 * storage counters move, and its guards still apply: a file handed in between
 * the query and the delete is refused there and simply left alone.
 */
final class SweepUnusedUploads extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'media:sweep-unused
        {--hours= : Override the configured grace period}
        {--dry-run : Report what would go, delete nothing}';

    protected $description = 'Delete uploads nothing used within their grace period, in every academy';

    public function handle(UploadQuota $quota, DeleteMedia $delete): int
    {
        $hours = (int) ($this->option('hours') ?? config('orbito.media.unused_grace_hours'));

        if ($hours < 1) {
            $this->error('The grace period must be at least one hour.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');
        $files = 0;
        $bytes = 0;
        $errors = 0;

        $failed = $this->forEachTenant(function () use ($quota, $delete, $cutoff, $dryRun, &$files, &$bytes, &$errors): void {
            $quota->unusedFiles()
                ->whereIn('collection', MediaCollection::swept())
                ->where('created_at', '<', $cutoff)
                // DeleteMedia reads them, and strict mode refuses a lazy load
                // on a model hydrated alongside others.
                ->with('variants')
                // By id, so rows soft-deleted mid-walk cannot shift a page.
                ->chunkById(200, function (Collection $chunk) use ($delete, $dryRun, &$files, &$bytes, &$errors): void {
                    /** @var Collection<int, Media> $chunk */
                    foreach ($chunk as $media) {
                        if (! $dryRun) {
                            try {
                                $delete->handle($media);
                            } catch (MediaInUse) {
                                // Used after all, between the query and now.
                                continue;
                            } catch (Throwable $e) {
                                // One stuck file must not stop the rest.
                                $errors++;
                                $this->error("[media {$media->id}] {$e->getMessage()}");
                                report($e);

                                continue;
                            }
                        }

                        $files++;
                        $bytes += $media->size_bytes;
                    }
                });
        });

        $this->info(sprintf(
            '%s %d unused upload(s), %s, older than %s.',
            $dryRun ? 'Would sweep' : 'Swept',
            $files,
            ByteSize::human($bytes),
            $cutoff->toDateTimeString(),
        ));

        return $failed === 0 && $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
