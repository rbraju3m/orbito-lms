<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Platform\Enums\Locale;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;
use App\Http\Requests\Concerns\ValidatesOwnedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAcademyRequest extends FormRequest
{
    use ValidatesOwnedMedia;

    public function authorize(): bool
    {
        return true; // The controller authorizes against AcademyPolicy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registration_mode' => ['sometimes', Rule::enum(RegistrationMode::class)],
            'support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            // Null takes the logo down.
            'logo_media_id' => ['sometimes', 'nullable', 'integer'],

            'default_locale' => ['sometimes', 'string', Rule::in($this->supportedCodes())],
            'enabled_locales' => ['sometimes', 'array', 'min:1'],
            'enabled_locales.*' => ['string', 'distinct', Rule::in($this->supportedCodes())],
        ];
    }

    /**
     * An id that merely exists is not authorized (§ Patterns established in
     * Phase 4): the logo must be a file the caller uploaded as a logo.
     *
     * Except the logo already there. A colleague may have uploaded it, and
     * re-sending what is stored must not be refused for it — ownership is
     * checked on what is NEW, the page builder's rule.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $id = $this->input('logo_media_id');
            $current = Tenant::find($this->user()?->tenant_id)?->logoMediaId();

            if (is_numeric($id) && (int) $id !== $current) {
                $this->assertOwnedMedia($validator, 'logo_media_id', MediaCollection::AcademyLogo);
            }

            $this->assertDefaultIsEnabled($validator);
        });
    }

    /**
     * The default must be a language the academy speaks, judged on the
     * MERGED result: a save changing only one of the two is checked against
     * what is stored for the other.
     */
    private function assertDefaultIsEnabled(Validator $validator): void
    {
        if (! $this->has('default_locale') && ! $this->has('enabled_locales')) {
            return;
        }

        $academy = Tenant::find($this->user()?->tenant_id);

        $default = $this->has('default_locale')
            ? $this->input('default_locale')
            : $academy?->defaultLocale()->value;

        $enabled = $this->has('enabled_locales')
            ? (array) $this->input('enabled_locales')
            : array_map(fn (Locale $locale): string => $locale->value, $academy?->enabledLocales() ?? Locale::supported());

        if (! in_array($default, $enabled, true)) {
            $validator->errors()->add(
                $this->has('enabled_locales') ? 'enabled_locales' : 'default_locale',
                'The default language must be one the academy offers.',
            );
        }
    }

    /** @return list<string> */
    private function supportedCodes(): array
    {
        return array_map(fn (Locale $locale): string => $locale->value, Locale::supported());
    }
}
