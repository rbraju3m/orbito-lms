<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use App\Domain\Catalog\Models\Course;
use App\Domain\Content\Data\LeadSubmission;
use App\Domain\Content\Enums\LeadSource;
use App\Domain\Live\Models\Webinar;
use App\Support\Http\FormTokenVerdict;
use App\Support\Http\PublicFormToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A lead form, posted by somebody with no account (docs/LEADS.md).
 *
 * Two outcomes beyond pass and fail, and `isTrap()` is how the controller
 * tells them apart: a filled honeypot or a form posted faster than a person
 * could have read it passes validation and is then DISCARDED, answered exactly
 * like a real lead so a script cannot learn which check it tripped.
 *
 * The source page is resolved HERE, by slug, against what a stranger could
 * actually have been reading — a live course, a published webinar. A draft's
 * slug gets the same 422 as a slug that never existed.
 */
final class SubmitLeadRequest extends FormRequest
{
    private ?FormTokenVerdict $verdict = null;

    /** @var array{id: int, title: string}|null */
    private ?array $subject = null;

    public function authorize(): bool
    {
        return true; // Anonymous by design — see PublicSite\LeadController.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `rfc` and not `dns`: a DNS lookup per anonymous POST is a way to
            // make our servers wait on somebody else's resolver.
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'source_slug' => [
                Rule::requiredIf(fn (): bool => $this->input('source') !== LeadSource::Site->value),
                'nullable', 'string', 'max:200',
            ],
            'form_token' => ['required', 'string', 'max:2000'],
            // The honeypot: a field no person sees, so anything in it was put
            // there by something filling every input it found.
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->verdict = app(PublicFormToken::class)->check(
                (string) $this->input('form_token'),
                (string) $this->route('academy'),
                now(),
            );

            if ($this->verdict === FormTokenVerdict::Expired || $this->verdict === FormTokenVerdict::Invalid) {
                $validator->errors()->add('form_token', 'This form has expired. Reload the page and try again.');

                return;
            }

            // A trap is answered as a success, so nothing past here may add an
            // error that would tell it apart from one.
            if ($this->isTrap() || ! $this->source()->namesSubject()) {
                return;
            }

            $this->subject = $this->resolveSubject($this->source(), (string) $this->input('source_slug'));

            if ($this->subject === null) {
                $validator->errors()->add('source_slug', 'There is no such page on this site.');
            }
        }];
    }

    public function isTrap(): bool
    {
        return $this->filled('website') || $this->verdict === FormTokenVerdict::TooFast;
    }

    public function submission(string $consentText): LeadSubmission
    {
        $name = trim((string) $this->input('name', ''));

        return new LeadSubmission(
            email: (string) $this->input('email'),
            name: $name === '' ? null : $name,
            source: $this->source(),
            sourceId: $this->subject['id'] ?? null,
            sourceTitle: $this->subject['title'] ?? null,
            consentText: $consentText,
        );
    }

    private function source(): LeadSource
    {
        return LeadSource::from((string) $this->input('source'));
    }

    /**
     * The same scopes the public pages read with, so a lead can only name a
     * page that would have rendered: `live()` admits an unlisted course
     * somebody was sent, and refuses a private or draft one.
     *
     * @return array{id: int, title: string}|null
     */
    private function resolveSubject(LeadSource $source, string $slug): ?array
    {
        $subject = match ($source) {
            LeadSource::Course => Course::query()->live()->where('slug', $slug)->first(['id', 'title']),
            LeadSource::Webinar => Webinar::query()->published()->where('slug', $slug)->first(['id', 'title']),
            LeadSource::Site => null,
        };

        return $subject === null
            ? null
            : ['id' => (int) $subject->getAttribute('id'), 'title' => (string) $subject->getAttribute('title')];
    }
}
