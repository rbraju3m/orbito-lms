<?php

declare(strict_types=1);

namespace App\Domain\Live\Models;

use App\Domain\Live\Data\ProviderAccount;
use App\Domain\Live\Enums\LiveProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * One academy's credentials for one meeting provider.
 *
 * `credentials` is an ENCRYPTED cast — ciphertext at rest, never in a query
 * log — and it is `$hidden`, so it cannot escape through a model somebody
 * serialised carelessly. The API says WHETHER a provider is connected, never
 * what with.
 *
 * @property LiveProvider $provider
 * @property array<string, string>|null $credentials
 * @property bool $is_active
 */
final class LiveProviderAccount extends Model
{
    protected $fillable = ['provider', 'credentials', 'is_active'];

    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => LiveProvider::class,
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function toProviderAccount(): ProviderAccount
    {
        return new ProviderAccount($this->credentials ?? []);
    }
}
