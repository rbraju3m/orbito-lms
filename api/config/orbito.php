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
        // POST /media, per person. More than anybody attaches by hand; how
        // much they may KEEP is the media quota below.
        'uploads' => (int) env('RATE_LIMIT_UPLOADS', 20),
        // The anonymous marketing surface, per IP. A landing page makes a
        // handful of calls and a crawler makes many; this is generous enough
        // for a real visitor and finite for a script.
        'public' => (int) env('RATE_LIMIT_PUBLIC', 90),
        // The lead form, the first anonymous WRITE (docs/LEADS.md §2): a burst
        // and a day per IP, and a cap per address per academy. One layer of
        // the defence, not the defence.
        'leads_per_minute' => (int) env('RATE_LIMIT_LEADS_PER_MINUTE', 5),
        'leads_per_day' => (int) env('RATE_LIMIT_LEADS_PER_DAY', 50),
        'leads_per_address' => (int) env('RATE_LIMIT_LEADS_PER_ADDRESS', 3),
        // A guest asking for a webinar place (docs/GUEST_REGISTRATION.md §2), per
        // IP. The cap on MAIL per address is separate and silent — see below.
        'guest_registrations_per_minute' => (int) env('RATE_LIMIT_GUEST_REGISTRATIONS_PER_MINUTE', 5),
        'guest_registrations_per_day' => (int) env('RATE_LIMIT_GUEST_REGISTRATIONS_PER_DAY', 30),
    ],

    /*
    | Every public form's token (`PublicFormToken`). A form posted less than
    | `min_seconds` after it was served was not filled in by a person; one
    | older than `token_ttl_hours` was abandoned.
    */
    'public_forms' => [
        'min_seconds' => (int) env('ORBITO_PUBLIC_FORMS_MIN_SECONDS', 3),
        'token_ttl_hours' => (int) env('ORBITO_PUBLIC_FORMS_TOKEN_TTL_HOURS', 24),
    ],

    /*
    | Lead capture (docs/LEADS.md). `consent` is what the form asks people to
    | agree to, and each lead stores a copy of the words it was shown.
    */
    'leads' => [
        'consent' => 'I agree to :academy contacting me by email about its courses and events. '
            .'I can ask for my details to be deleted at any time.',
    ],

    /*
    | Guest webinar registration (docs/GUEST_REGISTRATION.md). A confirmation
    | link lives `confirm_ttl_hours`. No more than `mails_per_address` mails go
    | to one address per academy in `mail_window_minutes` — silently, so the
    | form's answer never says whether one did.
    */
    'guest_registration' => [
        'confirm_ttl_hours' => (int) env('ORBITO_GUEST_CONFIRM_TTL_HOURS', 24),
        'mails_per_address' => (int) env('ORBITO_GUEST_MAILS_PER_ADDRESS', 3),
        'mail_window_minutes' => (int) env('ORBITO_GUEST_MAIL_WINDOW_MINUTES', 60),
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
        /*
         * What one person may hold in avatar and submission files they have
         * not used yet (`UploadQuota`). It must fit the largest submission an
         * assignment can ask for — 20 files of 25 MB — or a learner could not
         * assemble one; a test holds the default there.
         */
        'unattached_quota_bytes' => (int) env('MEDIA_UNATTACHED_QUOTA_MB', 512) * 1024 * 1024,
        /*
         * How long an upload nothing has used survives `media:sweep-unused`.
         * The submission form holds its files only in the page, so once that
         * is left they are unreachable; two days is longer than any sitting.
         */
        'unused_grace_hours' => (int) env('MEDIA_UNUSED_GRACE_HOURS', 48),
    ],

    /*
    | Coupons. See docs/COUPONS.md.
    */
    'coupons' => [
        // How long an UNPAID order holds the coupon use it took. After this,
        // an abandoned checkout gives the use back — read from the clock by
        // CouponRules, never swept.
        'reservation_minutes' => (int) env('COUPON_RESERVATION_MINUTES', 60),
    ],

    /*
    | Outbound webhooks (ADR-12). See docs/WEBHOOKS.md.
    */
    'webhooks' => [
        // Per attempt. A receiver should answer fast and do its work later.
        'timeout_seconds' => 10,
        // First try plus seven retries, spread over ~45 hours (DeliverWebhook).
        'max_attempts' => 8,
        // Deliveries in a row that used up every attempt before the endpoint
        // switches itself off — an address that has been dead for days.
        'disable_after_failures' => 5,
        // Settled deliveries are pruned after this; the log is for debugging.
        'retention_days' => 30,
        // A developer's own machine. Ignored in production (WebhookTarget).
        'allow_private_targets' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_TARGETS', false),
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
