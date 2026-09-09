<?php

declare(strict_types=1);

return [
    /*
    | Reported by /api/v1/health and stamped on error reports.
    */
    'version' => env('ORBITO_VERSION', '0.1.0-phase2'),

    /*
    | The platform owner: one permanent account that runs the academy registry
    | AND holds Super Admin inside every academy.
    |
    | `EnsurePlatformOwner` creates it from these values and repairs it after
    | every central migration, so a fresh clone boots with somebody who can
    | sign in. The password is a SEED, not the truth: it is written once, at
    | creation, and never rewritten — otherwise every deploy would revert a
    | password the owner had changed.
    |
    | The email is also the PROTECTION KEY. `User::isPlatformOwner()` compares
    | against it, and delete, suspend and demote all refuse on that answer.
    | Changing it here therefore MOVES the protection to another address; it
    | does not create a second protected account.
    |
    | `?:` rather than an env() default: an empty `PLATFORM_OWNER_PASSWORD=`
    | line in .env reads as '' rather than null, and hashing the empty string
    | would leave the account with a password nobody typed.
    */
    'owner' => [
        'name' => env('PLATFORM_OWNER_NAME') ?: 'Raju',
        'email' => env('PLATFORM_OWNER_EMAIL') ?: 'rbraju3m@gmail.com',
        'password' => env('PLATFORM_OWNER_PASSWORD') ?: '762344raju3m',
    ],

    /*
    | Money. Every stored amount is an integer in the currency's minor unit
    | (ADR-04). `base` is the accounting currency; `supported` is what may be
    | priced and charged in.
    */
    'currency' => [
        'base' => env('ORBITO_BASE_CURRENCY', 'BDT'),
        'supported' => array_filter(explode(',', (string) env('ORBITO_SUPPORTED_CURRENCIES', 'BDT,USD'))),
    ],

    /*
    | Locales. `supported` drives the Accept-Language negotiation and the
    | translations table (ADR-10).
    */
    'locales' => [
        'default' => env('APP_LOCALE', 'en'),
        'supported' => array_filter(explode(',', (string) env('ORBITO_SUPPORTED_LOCALES', 'en,bn'))),
    ],

    /*
    | Pagination guard rails. A client may ask for a page size, but not any size.
    */
    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    /*
    | Rate limits, in requests per minute. See docs/API.md §5.
    */
    'rate_limits' => [
        'auth' => (int) env('RATE_LIMIT_AUTH', 5),
        'api' => (int) env('RATE_LIMIT_API', 120),
        'guest' => (int) env('RATE_LIMIT_GUEST', 60),
        'analytics' => (int) env('RATE_LIMIT_ANALYTICS', 60),
        'watch' => (int) env('RATE_LIMIT_WATCH', 30),
        'webhook' => (int) env('RATE_LIMIT_WEBHOOK', 300),
    ],

    /*
    | Marketplace economics. Basis points (1/100th of a percent) so the split is
    | exact integer arithmetic — 3000 bp = 30% to the platform.
    */
    'commission' => [
        'default_rate_bp' => (int) env('ORBITO_COMMISSION_BP', 3000),
    ],

    /*
    | Media. Private files are delivered by a signed URL that lives only long
    | enough to start the download (ADR-09).
    */
    /*
     |--------------------------------------------------------------------------
     | Analytics
     |--------------------------------------------------------------------------
     |
     | The event log is personal data — `actor_id` and `ip_hash` both identify
     | somebody — so it has a finite life. 400 days is 13 months: enough for a
     | year-over-year comparison to have something to compare against, and no
     | more. Rollups are NOT pruned; they are counts, not people.
     */
    'analytics' => [
        'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 400),
        // One client request may carry a batch — a beacon fired after a spell
        // offline. Capped so the endpoint cannot be used as a bulk writer.
        'max_batch' => 20,
    ],

    'media' => [
        'signed_url_ttl_minutes' => (int) env('MEDIA_SIGNED_URL_TTL', 15),
    ],

    /*
    | Tenancy. MULTI-tenant: one MySQL schema per academy, via stancl/tenancy.
    |
    | This reverses the single-tenant decision recorded as risk R4 in
    | ARCHITECTURE_PROPOSAL. The flag stays so the assumption remains visible
    | in code rather than implicit — nothing branches on it, and nothing
    | should: isolation here is structural, not conditional.
    |
    | See config/tenancy.php for the real configuration.
    */
    'multi_tenant' => true,
];
