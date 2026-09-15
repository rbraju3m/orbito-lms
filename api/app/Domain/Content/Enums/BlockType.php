<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

use Illuminate\Validation\Rule;

/**
 * What a block on a page may be — a CLOSED set.
 *
 * There is no "custom HTML" block and no embed, on purpose: a page is edited
 * through the API and rendered to strangers, so every type is one whose props
 * can be validated and whose output the SPA renders itself. An unknown type
 * is REFUSED when the page is saved, never stored and skipped (§ Patterns
 * established in Phase 14: a closed set of operators, and an unknown one
 * refuses). Adding a type is a case here, its rules, and a renderer in
 * `PageBlocks.tsx` — nothing else.
 */
enum BlockType: string
{
    case Heading = 'heading';
    case Text = 'text';
    case Image = 'image';
    case Button = 'button';
    case Courses = 'courses';
    case Webinars = 'webinars';
    case Posts = 'posts';
    case LeadForm = 'lead_form';

    public function label(): string
    {
        return match ($this) {
            self::Heading => 'Heading',
            self::Text => 'Text',
            self::Image => 'Image',
            self::Button => 'Button',
            self::Courses => 'Courses',
            self::Webinars => 'Upcoming events',
            self::Posts => 'Latest posts',
            self::LeadForm => 'Stay-in-touch form',
        };
    }

    /**
     * The props this type accepts, and the rules for each, keyed relative to
     * the block's `props`. A key not listed here is DROPPED when the page is
     * saved (`PageBlocks::normalise()`), so nothing undeclared is ever stored
     * or rendered.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Heading => [
                'text' => ['required', 'string', 'max:200'],
                'level' => ['required', 'integer', 'in:2,3'],
            ],
            // Sanitised on WRITE, like every author HTML in the product.
            self::Text => [
                'html' => ['required', 'string', 'max:50000'],
            ],
            // The file must be the saver's own when it is ADDED — see
            // `SavePageBlocksRequest` for why only then.
            self::Image => [
                'media_ref' => ['required', 'integer'],
                'alt' => ['nullable', 'string', 'max:200'],
                'caption' => ['nullable', 'string', 'max:300'],
            ],
            /*
             * A web address or a path on this site. Never `javascript:` or
             * `data:`, and never `//host`, a protocol-relative link that reads
             * like a path and leaves the site.
             */
            self::Button => [
                'label' => ['required', 'string', 'max:60'],
                'url' => ['required', 'string', 'max:500', 'regex:/^(https?:\/\/\S+|\/(?!\/)\S*)$/'],
            ],
            // Uuids the builder picked. Resolved at render with `Course::live()`,
            // so a course that went back to draft simply drops out.
            self::Courses => [
                'title' => ['nullable', 'string', 'max:100'],
                'course_ids' => ['required', 'array', 'min:1', 'max:12'],
                'course_ids.*' => ['string', 'distinct', Rule::exists('courses', 'uuid')],
            ],
            self::Webinars, self::Posts => [
                'title' => ['nullable', 'string', 'max:100'],
                'limit' => ['required', 'integer', 'between:1,6'],
            ],
            self::LeadForm => [
                'title' => ['nullable', 'string', 'max:100'],
                'description' => ['nullable', 'string', 'max:300'],
            ],
        };
    }

    /**
     * The top-level prop keys — `rules()` without its wildcard entries.
     *
     * @return list<string>
     */
    public function props(): array
    {
        return array_values(array_filter(
            array_keys($this->rules()),
            static fn (string $key): bool => ! str_contains($key, '.'),
        ));
    }
}
