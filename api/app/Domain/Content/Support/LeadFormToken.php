<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

use App\Domain\Content\Enums\FormTokenVerdict;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;

/**
 * The token a lead form carries from the moment it was served to the moment
 * it is posted.
 *
 * It is ONE layer of the abuse story in docs/LEADS.md, and not a strong one on
 * its own: a script can fetch the form like a browser does. What it buys is
 * that posting costs a round trip and a wait, which a spray of POSTs at the
 * endpoint does not pay, and that a token minted for one academy is refused by
 * another. The limiter, the honeypot and the one-row-per-address constraint
 * are the rest.
 *
 * ENCRYPTED rather than signed, so the issue time is not readable either:
 * nothing in the token tells a script how long the minimum wait is.
 *
 * Stateless on purpose. Storing issued tokens would be a table a stranger can
 * fill by reloading a page, which is the problem this exists to reduce.
 */
final class LeadFormToken
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function mint(string $academy, CarbonInterface $at): string
    {
        return $this->encrypter->encryptString((string) json_encode([
            'academy' => $academy,
            'issued_at' => $at->getTimestamp(),
        ]));
    }

    public function check(string $token, string $academy, CarbonInterface $now): FormTokenVerdict
    {
        try {
            $payload = json_decode($this->encrypter->decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return FormTokenVerdict::Invalid;
        }

        if (! is_array($payload)
            || ($payload['academy'] ?? null) !== $academy
            || ! is_int($payload['issued_at'] ?? null)) {
            return FormTokenVerdict::Invalid;
        }

        $age = $now->getTimestamp() - $payload['issued_at'];

        /*
         * A negative age lands here too — a token issued by a server whose
         * clock runs ahead. Treated as too fast rather than invalid, because
         * no person fills a form in under the minimum either way.
         */
        if ($age < (int) config('orbito.leads.min_seconds')) {
            return FormTokenVerdict::TooFast;
        }

        if ($age > (int) config('orbito.leads.token_ttl_hours') * 3600) {
            return FormTokenVerdict::Expired;
        }

        return FormTokenVerdict::Valid;
    }
}
