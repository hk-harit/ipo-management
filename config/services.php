<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
     * ipoalerts.in — the IPO listing feed. Without a key the API still answers
     * but returns a single IPO per page and allows only 3 requests a minute,
     * so a key from https://ipoalerts.in is needed for a real sync.
     */
    'ipoalerts' => [
        'key' => env('IPOALERTS_API_KEY'),
        'base_url' => env('IPOALERTS_BASE_URL', 'https://api.ipoalerts.in'),
        'max_pages' => env('IPOALERTS_MAX_PAGES', 20),
    ],

    /*
     * chittorgarh.com — the second IPO feed. Needs no key and covers both
     * mainboard and SME in one call, but carries less detail (no price band,
     * and dates without a year). Used to fill in what ipoalerts.in misses.
     *
     * This is chittorgarh's internal endpoint rather than a published API, so
     * set CHITTORGARH_ENABLED=false if it ever starts misbehaving.
     */
    'chittorgarh' => [
        'enabled' => env('CHITTORGARH_ENABLED', true),
        'base_url' => env('CHITTORGARH_BASE_URL', 'https://webnodejs.chittorgarh.com'),
        'site_url' => env('CHITTORGARH_SITE_URL', 'https://www.chittorgarh.com'),
    ],

    /*
     * investorgain.com — the grey market premium feed, also key-free. GMP is an
     * unofficial quote from an unregulated market: a useful signal about likely
     * listing gains, never a guarantee. Set INVESTORGAIN_ENABLED=false to drop it.
     */
    'investorgain' => [
        'enabled' => env('INVESTORGAIN_ENABLED', true),
        'base_url' => env('INVESTORGAIN_BASE_URL', 'https://webnodejs.investorgain.com'),
        'site_url' => env('INVESTORGAIN_SITE_URL', 'https://www.investorgain.com'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Shared secret for the /cron/* URLs. Read here (not with env() in routes)
     * so it still works after `php artisan config:cache`. No default on purpose:
     * leave CRON_TOKEN empty and those URLs refuse every request.
     */
    'cron' => [
        'token' => env('CRON_TOKEN'),
    ],

    /*
     * tesseract-ocr — reads the numeric captcha on Bigshare's allotment
     * checker (BigshareChecker). Needs the binary installed on the server;
     * point TESSERACT_BINARY at a full path if it's not on PATH.
     */
    'tesseract' => [
        'binary' => env('TESSERACT_BINARY', 'tesseract'),
    ],

];
