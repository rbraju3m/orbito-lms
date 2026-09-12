<?php

declare(strict_types=1);

namespace App\Http\Requests\Live;

use App\Domain\Live\Data\CredentialField;
use App\Domain\Live\Enums\LiveProvider;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Connecting an academy's own meeting provider.
 *
 * Everything is `sometimes`: this is a partial update, so switching a
 * provider off must not require re-typing a secret Zoom shows once.
 * `ConnectLiveProvider` keeps what it is not given, and decides completeness
 * from the merged result.
 *
 * The allowed KEYS come from `LiveProvider::credentialFields()` — the same
 * declaration the screen renders — so a typo'd key is refused here rather
 * than stored in an encrypted blob nothing will ever read.
 */
final class ConnectLiveProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes the capability.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $provider = $this->provider();

        $keys = $provider === null ? [] : array_map(
            static fn (CredentialField $field): string => $field->key,
            $provider->credentialFields(),
        );

        return [
            // `array:a,b,c` refuses any other key. An unknown provider leaves
            // the list open and the controller answers 404 a moment later.
            'credentials' => ['sometimes', $keys === [] ? 'array' : 'array:'.implode(',', $keys)],
            /*
             * String values only — an array here would be serialised into the
             * encrypted blob and come back a shape no provider can read. The
             * cap is far above a payment gateway's because a Google service
             * account's private key is a PEM block, not a token.
             */
            'credentials.*' => ['present', 'string', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function provider(): ?LiveProvider
    {
        $provider = $this->route('provider');

        return is_string($provider) ? LiveProvider::tryFrom($provider) : null;
    }

    /** @return array<string, string>|null */
    public function credentials(): ?array
    {
        if (! $this->has('credentials')) {
            return null;
        }

        /** @var array<string, string> $credentials */
        $credentials = $this->array('credentials');

        return $credentials;
    }
}
