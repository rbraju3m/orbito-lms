<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Domain\Content\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A page's settings, partially. The SLUG locks at the first publication and
 * stays locked — the blog's rule, for the same reason: a link somebody shared
 * must keep landing.
 */
final class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $page = $this->route('page');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('pages', 'slug')->ignore($page instanceof Page ? $page->id : null),
            ],
            'show_in_nav' => ['sometimes', 'boolean'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $page = $this->route('page');

            if ($page instanceof Page
                && $this->has('slug')
                && $this->input('slug') !== $page->slug
                && $page->published_at !== null) {
                $validator->errors()->add('slug', 'A page that has been published keeps its address, so links to it keep working.');
            }
        }];
    }
}
