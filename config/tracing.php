<?php

return [

    /*
    | Distributed tracing with OpenTelemetry (OTLP/HTTP). Traces follow a notification from the
    | API request through the queue to the worker and the provider call (W3C traceparent).
    */
    'enabled' => (bool) env('OTEL_ENABLED', false),

    'service_name' => env('OTEL_SERVICE_NAME', 'eda-notification-system'),

    // OTLP/HTTP base URL; spans are sent to {endpoint}/v1/traces.
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),

    // Export timeout (seconds). Kept short: tracing must never slow delivery down.
    'timeout' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT', 1),

    // Record database queries as child spans of the active span.
    'db_spans' => (bool) env('OTEL_DB_SPANS', true),

];
