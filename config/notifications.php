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
    | External provider (simulated with webhook.site).
    */
    'provider' => [
        'url' => env('NOTIFICATION_PROVIDER_URL'),
        'timeout' => (float) env('NOTIFICATION_PROVIDER_TIMEOUT', 5),
    ],

];
