<?php

declare(strict_types=1);

if (! function_exists('frontend_url')) {
    /**
     * Base URL of the SPA. FRONTEND_URL may hold several comma-separated
     * origins for CORS; the first is the canonical one for links in emails.
     */
    function frontend_url(): string
    {
        $configured = (string) config('app.frontend_url', 'http://localhost:5173');

        return trim(explode(',', $configured)[0]);
    }
}
