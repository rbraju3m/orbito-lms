<?php

declare(strict_types=1);

namespace App\Http\Requests\Certification;

use App\Domain\Certification\Enums\TemplateOrientation;
use App\Domain\Media\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

final class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes the capability.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'orientation' => [$required, new Enum(TemplateOrientation::class)],
            'background_media_id' => ['sometimes', 'nullable', 'integer', Rule::exists('media', 'id')],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],

            'layout' => ['sometimes', 'array'],
            'layout.heading' => ['sometimes', 'string', 'max:120'],
            'layout.body' => ['sometimes', 'string', 'max:1000'],
            'layout.signature_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'layout.signature_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            /*
             * A colour, not a CSS fragment. The renderer validates this again
             * before interpolating — a template field that reaches a
             * stylesheet unchecked is a CSS injection into every certificate
             * the academy issues.
             */
            'layout.accent_colour' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'layout.show_score' => ['sometimes', 'boolean'],
            'layout.show_qr' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mediaId = $this->input('background_media_id');

            if ($mediaId === null) {
                return;
            }

            /*
             * An id that merely `exists` is not authorized (CLAUDE.md §10).
             * Referencing somebody else's upload by id must be rejected here,
             * not merely validated for existence — otherwise a template can
             * embed any file in the academy into a document.
             */
            $media = Media::find($mediaId);

            if ($media === null || $media->owner_id !== $this->user()?->id) {
                $validator->errors()->add('background_media_id', 'That image is not available.');
            }
        });
    }
}
