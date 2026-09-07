<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Support\Str;

/**
 * Correlation id for a single request. Set by AssignRequestId, echoed in the
 * X-Request-Id header, attached to every log line, and returned in error bodies
 * so a user-reported failure can be found in the logs.
 */
final class RequestId
{
    private static ?string $id = null;

    public static function set(string $id): void
    {
        self::$id = $id;
    }

    public static function current(): string
    {
        return self::$id ??= (string) Str::ulid();
    }

    public static function reset(): void
    {
        self::$id = null;
    }
}
