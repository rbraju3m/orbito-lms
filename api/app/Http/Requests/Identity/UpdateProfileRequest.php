<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\Models\UserSocialLink;
use App\Domain\Platform\Enums\Locale;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            // Only a language this academy speaks: the switcher offers exactly
            // these (`/auth/me` → `locale.available`), so nothing else is asked.
            // Null hands the choice back to the academy.
            'locale' => ['sometimes', 'nullable', 'string', Rule::in($this->enabledLocaleCodes())],

            'social_links' => ['sometimes', 'array'],
            'social_links.*' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<string, mixed> $links */
            $links = $this->input('social_links', []);

            foreach (array_keys($links) as $platform) {
                if (! in_array($platform, UserSocialLink::PLATFORMS, true)) {
                    $validator->errors()->add(
                        "social_links.{$platform}",
                        "Unsupported platform [{$platform}]."
                    );
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function profileAttributes(): array
    {
        return $this->only(['name', 'phone', 'headline', 'bio', 'timezone', 'locale']);
    }

    /** @return array<string, string>|null */
    public function socialLinks(): ?array
    {
        if (! $this->has('social_links')) {
            return null;
        }

        /** @var array<string, string> */
        return array_filter($this->input('social_links', []), fn ($url) => is_string($url) && $url !== '');
    }

    /** @return list<string> */
    private function enabledLocaleCodes(): array
    {
        $academy = Tenant::find($this->user()?->tenant_id);

        return array_map(
            fn (Locale $locale): string => $locale->value,
            $academy?->enabledLocales() ?? Locale::supported(),
        );
    }
}
