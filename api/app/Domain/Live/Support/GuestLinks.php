<?php

declare(strict_types=1);

namespace App\Domain\Live\Support;

use App\Domain\Live\Models\LiveSession;

/**
 * Where a guest's emails point, and how they say when the event is.
 *
 * Every link lands on the academy's PUBLIC site (`/a/{academy}/…`), which is
 * the only part of the SPA somebody with no account can open. The token goes
 * in the query string to reach the SPA, and the SPA POSTs it — the API never
 * accepts a credential on a GET, which is what proxies and access logs record.
 */
final class GuestLinks
{
    public static function confirm(string $academy, string $webinarSlug, string $token): string
    {
        return self::page($academy, $webinarSlug, 'confirm', $token);
    }

    public static function place(string $academy, string $webinarSlug, string $token): string
    {
        return self::page($academy, $webinarSlug, 'place', $token);
    }

    /**
     * " on 1 Oct 2026, 19:00 (Asia/Dhaka)" — in the zone it was SCHEDULED in.
     * A wall-clock time printed from one zone and labelled with another reads
     * as authoritative and is hours out (§ Patterns established in Phase 15).
     */
    public static function when(?LiveSession $session): string
    {
        if ($session === null) {
            return '';
        }

        return ' on '.$session->starts_at->copy()->setTimezone($session->timezone)->format('j M Y, H:i')
            .' ('.$session->timezone.')';
    }

    private static function page(string $academy, string $webinarSlug, string $page, string $token): string
    {
        return rtrim(frontend_url(), '/')
            .'/a/'.rawurlencode($academy)
            .'/webinars/'.rawurlencode($webinarSlug)
            .'/'.$page.'?token='.rawurlencode($token);
    }
}
