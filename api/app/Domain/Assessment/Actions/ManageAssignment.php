<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\Assignment;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

/**
 * Assignment authoring: the settings and the attachments that go with them.
 */
final class ManageAssignment
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>|null  $attachmentIds  media ids, already checked for
     *                                         ownership; null leaves them alone
     */
    public function update(Assignment $assignment, array $attributes, ?array $attachmentIds): Assignment
    {
        if (array_key_exists('instructions', $attributes)) {
            $attributes['instructions'] = $this->sanitizer->clean($attributes['instructions']);
        }

        DB::transaction(function () use ($assignment, $attributes, $attachmentIds): void {
            $assignment->fill($attributes)->save();

            if ($attachmentIds !== null) {
                $assignment->attachments()->sync(
                    collect($attachmentIds)
                        ->values()
                        ->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])
                        ->all()
                );
            }
        });

        return $assignment->refresh()->load('attachments');
    }
}
