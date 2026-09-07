<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Domain\Assessment\Models\Assignment;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates what the learner hands in.
 *
 * File rules live here because they produce field-level errors the form can
 * show. The rules that depend on domain state — attempts left, the deadline —
 * live in `SubmitAssignment`, which answers with 409.
 */
final class StoreSubmissionRequest extends FormRequest
{
    /** @var Collection<int, Media>|null */
    private ?Collection $resolved = null;

    public function authorize(): bool
    {
        return true; // The controller resolves access through CourseAccess.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'media_ids' => ['sometimes', 'array', 'max:20'],
            'media_ids.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assignment = $this->assignment();

            if ($assignment === null) {
                return;
            }

            $ids = $this->mediaIds();

            if ($ids === []) {
                return;
            }

            if (count($ids) > $assignment->max_files) {
                $validator->errors()->add(
                    'media_ids',
                    "Attach at most {$assignment->max_files} files."
                );

                return;
            }

            /*
             * Ownership first. An id that merely `exists` is not authorized —
             * otherwise a learner could hand in somebody else's upload, and
             * read a private file by attaching it to their own submission.
             */
            $media = Media::query()
                ->whereIn('id', $ids)
                ->where('owner_id', $this->user()?->id)
                ->where('collection', MediaCollection::Submission->value)
                ->get();

            if ($media->count() !== count($ids)) {
                $validator->errors()->add('media_ids', 'One of those files is not yours to submit.');

                return;
            }

            $allowed = $assignment->extensionAllowlist();
            $maxBytes = $assignment->maxFileBytes();

            foreach ($media as $file) {
                if ($allowed !== null && ! in_array(mb_strtolower($file->extension), $allowed, true)) {
                    $validator->errors()->add(
                        'media_ids',
                        "“{$file->original_name}” is not an accepted file type."
                    );
                }

                if ($file->size_bytes > $maxBytes) {
                    $validator->errors()->add(
                        'media_ids',
                        "“{$file->original_name}” is larger than this assignment allows."
                    );
                }
            }

            $this->resolved = $media;
        });
    }

    /** @return list<int> */
    public function mediaIds(): array
    {
        /** @var list<int> */
        return array_values(array_unique(array_map('intval', (array) $this->input('media_ids', []))));
    }

    /**
     * The validated files, in the order the learner listed them.
     *
     * @return Collection<int, Media>
     */
    public function files(): Collection
    {
        /** @var Collection<int, Media> */
        return $this->resolved ?? new Collection;
    }

    private function assignment(): ?Assignment
    {
        $item = $this->route('item');

        if (! $item instanceof CourseItem) {
            return null;
        }

        $item->loadMissing('itemable');

        return $item->itemable instanceof Assignment ? $item->itemable : null;
    }
}
