<?php

// Config for Silverspoonmedia/VtualService
//
// Ported from the upstream YouTube-operational-API native PHP files:
//   - constants.php
//   - configuration.php
//
// Every value can be overridden through the matching environment variable so
// the package stays configurable per deployment without editing source.

return [

    /*
    |--------------------------------------------------------------------------
    | Instance identity
    |--------------------------------------------------------------------------
    | Upstream: configuration.php SERVER_NAME
    */
    'server_name' => env('VTUAL_SERVER_NAME', 'my instance'),

    /*
    |--------------------------------------------------------------------------
    | Web-scraping behaviour
    |--------------------------------------------------------------------------
    | Upstream: configuration.php
    */
    'google_abuse_exemption' => env('VTUAL_GOOGLE_ABUSE_EXEMPTION', ''),
    'multiple_ids_enabled' => env('VTUAL_MULTIPLE_IDS_ENABLED', true),
    'multiple_ids_maximum' => (int) env('VTUAL_MULTIPLE_IDS_MAXIMUM', 50),

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP proxy (recommended for scraping)
    |--------------------------------------------------------------------------
    | Route ALL YouTube/Google scraping through a proxy so Google sees the
    | proxy egress IP instead of your application server IP.
    |
    | Option A — single URL (credentials embedded):
    |   VTUAL_HTTPS_PROXY=http://user:pass@proxy.example.com:8080
    |
    | Option B — separate fields (upstream-compatible):
    |   VTUAL_HTTPS_PROXY_ADDRESS=proxy.example.com
    |   VTUAL_HTTPS_PROXY_PORT=8080
    |   VTUAL_HTTPS_PROXY_USERNAME=user
    |   VTUAL_HTTPS_PROXY_PASSWORD=pass
    |
    | Types (VTUAL_PROXY_TYPE):
    |   http    — HTTP CONNECT proxy (PHP streams; default)
    |   socks5  — SOCKS5 proxy (requires ext-curl)
    |   socks5h — SOCKS5 with remote DNS (requires ext-curl)
    |   tor     — Preset socks5h to local Tor (127.0.0.1:9050; requires ext-curl)
    |
    | Tor example:
    |   VTUAL_PROXY_TYPE=tor
    |   # optional: VTUAL_TOR_HOST=127.0.0.1  VTUAL_TOR_PORT=9050
    |   # egress is verified via check.torproject.org before scraping (fail-closed)
    |
    | SOCKS URL example:
    |   VTUAL_HTTPS_PROXY=socks5h://127.0.0.1:9050
    |
    | Set VTUAL_PROXY_ENABLED=false to force direct connections even when an
    | address/URL is present.
    */
    'proxy' => [
        'enabled' => env('VTUAL_PROXY_ENABLED'),
        'type' => env('VTUAL_PROXY_TYPE', 'http'),
        'url' => env('VTUAL_HTTPS_PROXY', ''),
        'address' => env('VTUAL_HTTPS_PROXY_ADDRESS', ''),
        'port' => (int) env('VTUAL_HTTPS_PROXY_PORT', 8080),
        'username' => env('VTUAL_HTTPS_PROXY_USERNAME', ''),
        'password' => env('VTUAL_HTTPS_PROXY_PASSWORD', ''),
        'tor' => [
            'host' => env('VTUAL_TOR_HOST', '127.0.0.1'),
            'port' => (int) env('VTUAL_TOR_PORT', 9050),
            // Before scraping, verify egress via check.torproject.org through Tor.
            // Fails closed — scraping is aborted if IsTor is not true.
            'verify_egress' => env('VTUAL_TOR_VERIFY_EGRESS', true),
            'verify_url' => env('VTUAL_TOR_VERIFY_URL', 'https://check.torproject.org/api/ip'),
            'verify_cache_seconds' => (int) env('VTUAL_TOR_VERIFY_CACHE_SECONDS', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | No-key service
    |--------------------------------------------------------------------------
    | Upstream: configuration.php KEYS_FILE / RESTRICT_USAGE_TO_KEY /
    |           ADD_KEY_FORCE_SECRET / ADD_KEY_TO_INSTANCES
    |
    | `keys_file` is resolved through Laravel's storage by default instead of a
    | path relative to the web root like the native project used.
    */
    'keys_file' => env('VTUAL_KEYS_FILE', storage_path('app/vtual-service/keys.txt')),
    'restrict_usage_to_key' => env('VTUAL_RESTRICT_USAGE_TO_KEY', ''),
    // If empty a random secret is generated at runtime to prevent denial-of-service.
    'add_key_force_secret' => env('VTUAL_ADD_KEY_FORCE_SECRET', ''),
    'add_key_to_instances' => array_values(array_filter(
        explode(',', (string) env('VTUAL_ADD_KEY_TO_INSTANCES', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Innertube / client versions
    |--------------------------------------------------------------------------
    | Upstream: constants.php
    */
    'sub_version_str' => '.9999099',
    // These are derived from sub_version_str at runtime, exposed here so they
    // can be pinned without code changes if YouTube shifts versioning.
    'music_version' => env('VTUAL_MUSIC_VERSION', '2.9999099'),
    'client_version' => env('VTUAL_CLIENT_VERSION', '1.9999099'),
    // This is NOT a YouTube Data API v3 key, it is the public Innertube UI key.
    'ui_key' => env('VTUAL_UI_KEY', 'AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8'),
    'user_agent' => env('VTUAL_USER_AGENT', 'Firefox/100'),

    /*
    |--------------------------------------------------------------------------
    | Unusual-traffic detection
    |--------------------------------------------------------------------------
    | Upstream: constants.php HTTP_CODES_DETECTED_AS_SENDING_UNUSUAL_TRAFFIC
    */
    'unusual_traffic_http_codes' => [302, 403, 429],

    /*
    |--------------------------------------------------------------------------
    | HTTP routes
    |--------------------------------------------------------------------------
    | Controls the optional REST layer that mirrors the original .htaccess
    | rewrite rules (search, videos, channels, ...). Disable if you only want
    | to use the package programmatically through the facade / services.
    */
    'routes' => [
        'enabled' => env('VTUAL_ROUTES_ENABLED', true),
        'prefix' => env('VTUAL_ROUTES_PREFIX', 'youtube'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Community post comments (authenticated Innertube)
    |--------------------------------------------------------------------------
    | Upstream community.php used censored placeholder cookies. Set real
    | browser session cookies here for the community endpoint to work.
    */
    'community' => [
        'sapisid' => env('VTUAL_COMMUNITY_SAPISID', ''),
        'secure_3psid' => env('VTUAL_COMMUNITY_SECURE_3PSID', ''),
    ],
];
