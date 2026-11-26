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
    | Redis counter shared by all workers, so it holds no matter how many run.
    */
    'rate_limit' => [
        'per_second' => (int) env('NOTIFICATION_RATE_LIMIT_PER_SECOND', 100),
    ],

    /*
    | External provider (simulated with webhook.site).
    */
    'provider' => [
        'url' => env('NOTIFICATION_PROVIDER_URL'),
        'timeout' => (float) env('NOTIFICATION_PROVIDER_TIMEOUT', 5),
    ],

];
