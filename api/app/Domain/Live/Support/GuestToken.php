<?php

declare(strict_types=1);

namespace App\Domain\Live\Support;

use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;

/**
 * The two credentials a guest ever holds, because they hold no account
 * (docs/GUEST_REGISTRATION.md).
 *
 *  - A CONFIRMATION token rides in the email the form sends. Holding it proves
 *    somebody can read that mailbox, and it is the ONLY thing that can put a
 *    place in the database: the form itself writes nothing. It names the
 *    webinar, the address and the name typed, and expires in hours.
 *  - A PLACE token rides in every mail about the place afterwards. It opens
 *    the manage page — see it, join it, give it up — and lasts until a day
 *    after the event ends, so the link in a reminder still works for somebody
 *    who clicks it late.
 *
 * Both are ENCRYPTED, so nothing in them is readable, and both carry a
 * PURPOSE and the academy's slug: a confirmation cannot be replayed as a place
 * token, and neither works on another academy's site.
 *
 * Stateless, like the public form token. A table of issued links would be a
 * table a stranger fills by typing addresses into a form.
 */
final class GuestToken
{
    private const CONFIRM = 'confirm';

    private const PLACE = 'place';

    public function __construct(private readonly Encrypter $encrypter) {}

    public function confirmation(string $academy, Webinar $webinar, string $email, ?string $name, CarbonInterface $now): string
    {
        return $this->seal([
            'p' => self::CONFIRM,
            'a' => $academy,
            'w' => $webinar->uuid,
            'e' => $email,
            'n' => $name,
            'x' => $now->copy()->addHours((int) config('orbito.guest_registration.confirm_ttl_hours'))->getTimestamp(),
        ]);
    }

    public function place(string $academy, WebinarRegistration $registration, ?LiveSession $session, CarbonInterface $now): string
    {
        $expires = $session !== null
            ? $session->ends_at->copy()->addDay()
            // A webinar with no time yet cannot be joined, but the place can
            // still be seen and given up.
            : $now->copy()->addDays(90);

        return $this->seal([
            'p' => self::PLACE,
            'a' => $academy,
            'r' => $registration->id,
            'e' => $registration->email,
            'x' => $expires->getTimestamp(),
        ]);
    }

    /** @return array{webinar: string, email: string, name: string|null}|null */
    public function readConfirmation(string $token, string $academy, CarbonInterface $now): ?array
    {
        $payload = $this->open($token, self::CONFIRM, $academy, $now);

        if ($payload === null || ! is_string($payload['w'] ?? null) || ! is_string($payload['e'] ?? null)) {
            return null;
        }

        return [
            'webinar' => $payload['w'],
            'email' => $payload['e'],
            'name' => is_string($payload['n'] ?? null) ? $payload['n'] : null,
        ];
    }

    /** @return array{registration: int, email: string}|null */
    public function readPlace(string $token, string $academy, CarbonInterface $now): ?array
    {
        $payload = $this->open($token, self::PLACE, $academy, $now);

        if ($payload === null || ! is_int($payload['r'] ?? null) || ! is_string($payload['e'] ?? null)) {
            return null;
        }

        return ['registration' => $payload['r'], 'email' => $payload['e']];
    }

    /** @param  array<string, mixed>  $payload */
    private function seal(array $payload): string
    {
        return $this->encrypter->encryptString((string) json_encode($payload));
    }

    /** @return array<string, mixed>|null */
    private function open(string $token, string $purpose, string $academy, CarbonInterface $now): ?array
    {
        try {
            $payload = json_decode($this->encrypter->decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['p'] ?? null) !== $purpose
            || ($payload['a'] ?? null) !== $academy
            || ! is_int($payload['x'] ?? null)
            || $payload['x'] < $now->getTimestamp()) {
            return null;
        }

        return $payload;
    }
}
