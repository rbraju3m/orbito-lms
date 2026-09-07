<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Domain\Assessment\Enums\LatePolicy;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'instructions' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'total_points' => ['sometimes', 'numeric', 'min:1', 'max:10000'],
            'passing_points' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],

            'due_at' => ['sometimes', 'nullable', 'date'],
            'late_policy' => ['sometimes', Rule::enum(LatePolicy::class)],
            'late_penalty_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],

            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],

            'allow_text' => ['sometimes', 'boolean'],
            'allow_files' => ['sometimes', 'boolean'],
            'max_file_size_kb' => ['sometimes', 'integer', 'min:64', 'max:25600'],
            'max_files' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'allowed_extensions' => ['sometimes', 'nullable', 'array', 'max:20'],
            'allowed_extensions.*' => ['string', 'max:10', 'regex:/^\.?[a-zA-Z0-9]+$/'],

            'attachment_media_ids' => ['sometimes', 'array', 'max:10'],
            'attachment_media_ids.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // An assignment that takes neither text nor files cannot be handed
            // in at all — better caught here than by a confused learner.
            if ($this->has('allow_text') || $this->has('allow_files')) {
                $text = $this->boolean('allow_text', true);
                $files = $this->boolean('allow_files', true);

                if (! $text && ! $files) {
                    $validator->errors()->add(
                        'allow_text',
                        'Allow a written answer, file uploads, or both.'
                    );
                }
            }

            if ($this->input('late_policy') === LatePolicy::Penalise->value
                && (int) $this->input('late_penalty_percent', 0) === 0) {
                $validator->errors()->add(
                    'late_penalty_percent',
                    'Set the penalty, or accept late work in full instead.'
                );
            }

            $this->assertOwnedAttachments($validator);
        });
    }

    /**
     * The attachment ids, or null when the caller did not mention them.
     *
     * @return list<int>|null
     */
    public function attachmentIds(): ?array
    {
        if (! $this->has('attachment_media_ids')) {
            return null;
        }

        /** @var list<int> */
        return array_map('intval', $this->input('attachment_media_ids', []));
    }

    /**
     * An id that merely `exists` is not authorized: without this, an author
     * could attach any file in the system by guessing its id.
     */
    private function assertOwnedAttachments(Validator $validator): void
    {
        $ids = $this->attachmentIds();

        if ($ids === null || $ids === []) {
            return;
        }

        $usable = Media::query()
            ->whereIn('id', $ids)
            ->where('owner_id', $this->user()?->id)
            ->whereIn('collection', [
                MediaCollection::LessonAttachment->value,
                MediaCollection::Submission->value,
            ])
            ->pluck('id')
            ->all();

        if (count($usable) !== count(array_unique($ids))) {
            $validator->errors()->add(
                'attachment_media_ids',
                'One of those files is not available for this assignment.'
            );
        }
    }
}
