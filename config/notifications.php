<?php

return [

    /*
    | Maximum number of notifications accepted in a single batch request.
    */
    'batch_max' => (int) env('NOTIFICATION_BATCH_MAX', 1000),

    /*
    | Pagination for the list endpoint.
    */
    'per_page_default' => 25,
    'per_page_max' => 100,

    /*
    | Maximum messages per second sent to the provider, per channel. Enforced with a
    | Redis sliding window shared by all workers: never more than this many in any
    | one-second span, no matter how many workers run.
    */
    'rate_limit' => [
        'per_second' => (int) env('NOTIFICATION_RATE_LIMIT_PER_SECOND', 100),
    ],

    /*
    | Automatic retries for temporary provider failures (timeouts, 5xx, 429).
    | Delay before attempt n+1 = min(max_delay, base_delay * multiplier^(n-1)) ± jitter,
    | and never shorter than the provider's Retry-After. Defaults: ~10s, 30s, 90s, 270s.
    */
    'retry' => [
        'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 5),
        'base_delay' => (int) env('NOTIFICATION_RETRY_BASE_DELAY', 10),
        'multiplier' => (float) env('NOTIFICATION_RETRY_MULTIPLIER', 3),
        'max_delay' => (int) env('NOTIFICATION_RETRY_MAX_DELAY', 900),
        'jitter' => 0.2,
    ],

    /*
    | Per-channel circuit breaker: after `failure_threshold` consecutive temporary
    | failures within `window` seconds, the channel pauses for `cooldown` seconds.
    */
    'circuit_breaker' => [
        'failure_threshold' => (int) env('NOTIFICATION_CB_THRESHOLD', 10),
        'window' => (int) env('NOTIFICATION_CB_WINDOW', 60),
        'cooldown' => (int) env('NOTIFICATION_CB_COOLDOWN', 30),
    ],

    /*
    | Recovery sweep (runs every minute): seconds after which a notification is
    | considered stuck in a non-final state.
    */
    'recovery' => [
        'pending_after' => 60,
        'processing_after' => 120,
        'queued_after' => 900,
    ],

    /*
    | WebSocket port as seen from the browser (used by the /realtime demo page).
    */
    'realtime' => [
        'public_port' => (int) env('REVERB_PUBLIC_PORT', 8085),
    ],

    /*
    | External provider (simulated with webhook.site).
    */
    'provider' => [
        'url' => env('NOTIFICATION_PROVIDER_URL'),
        'timeout' => (float) env('NOTIFICATION_PROVIDER_TIMEOUT', 5),
        // Optional fallback (e.g. the bundled mock provider) used when the primary answers
        // 429 / 404 / 410: webhook.site's quota is used up or the URL has expired.
        'fallback_url' => env('NOTIFICATION_FALLBACK_PROVIDER_URL'),
        // Seconds to stay on the fallback before trying the primary again.
        'failover_cooldown' => (int) env('NOTIFICATION_PROVIDER_FAILOVER_COOLDOWN', 300),
    ],

];
