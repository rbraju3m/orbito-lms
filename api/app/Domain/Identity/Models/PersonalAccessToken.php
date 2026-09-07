<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model, pinned to the central database.
 *
 * Tokens belong to an ACCOUNT, and accounts are central. Sanctum's own model
 * declares no connection, so it follows the default — which is the academy's
 * schema for the whole of an authenticated request. Every token lookup after
 * the first would then go looking for `personal_access_tokens` inside a tenant
 * database that has no such table.
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $connection = 'mysql';
}
