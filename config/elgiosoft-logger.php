<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Collector connection
    |--------------------------------------------------------------------------
    |
    | Nothing is captured or sent unless the SDK is enabled AND a key and an
    | endpoint are configured, so it is safe to install everywhere.
    |
    */

    'enabled' => env('ELGIOSOFT_LOGGER_ENABLED', true),

    // The hosted collector. Override only for a self-hosted or local collector (locally: http://elgiologs.test).
    'endpoint' => env('ELGIOSOFT_LOGGER_ENDPOINT', 'https://elgiologs.com'),

    'key' => env('ELGIOSOFT_LOGGER_KEY'),

    // Service (project) name. With an account key, one key serves every app and this picks the
    // project (created on first use). Ignored by project keys. Defaults to APP_NAME.
    'service' => env('ELGIOSOFT_LOGGER_SERVICE'),

    // Defaults to APP_ENV, release to null and server name to the host name.
    'environment' => env('ELGIOSOFT_LOGGER_ENVIRONMENT'),

    'release' => env('ELGIOSOFT_LOGGER_RELEASE'),

    'server_name' => env('ELGIOSOFT_LOGGER_SERVER_NAME'),

    // Minimum level for logs captured through the facade (the log channel has its own "level").
    'level' => env('ELGIOSOFT_LOGGER_LEVEL', 'debug'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | queue    – buffered events are pushed as a job onto your own queue and a worker
    |            POSTs them to the collector (recommended in production).
    | deferred – buffered events are POSTed after the response has been sent.
    | sync     – every event is POSTed immediately (tests, debugging, CLI).
    |
    */

    'transport' => env('ELGIOSOFT_LOGGER_TRANSPORT', 'queue'),

    'queue' => [
        'connection' => env('ELGIOSOFT_LOGGER_QUEUE_CONNECTION'),
        'queue' => env('ELGIOSOFT_LOGGER_QUEUE', 'default'),
        'tries' => (int) env('ELGIOSOFT_LOGGER_QUEUE_TRIES', 3),
    ],

    // Seconds. The SDK never waits longer than this for the collector.
    'timeout' => (float) env('ELGIOSOFT_LOGGER_TIMEOUT', 2),

    'compress' => env('ELGIOSOFT_LOGGER_COMPRESS', true),

    // Buffered logs + spans before an early flush.
    'max_buffer' => (int) env('ELGIOSOFT_LOGGER_MAX_BUFFER', 200),

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    */

    'tracing' => [
        'enabled' => env('ELGIOSOFT_LOGGER_TRACING', true),

        // Share of new traces whose spans are recorded (logs always carry the trace id).
        'sample_rate' => (float) env('ELGIOSOFT_LOGGER_SAMPLE_RATE', 1.0),

        'requests' => true,

        // Send every request's summary (method, path, status, duration) even when its trace is not
        // sampled, so the Requests page lists all of them. Its child spans still follow sample_rate.
        'all_requests' => env('ELGIOSOFT_LOGGER_ALL_REQUESTS', true),

        'db_queries' => true,
        'db_min_duration_ms' => (float) env('ELGIOSOFT_LOGGER_DB_MIN_DURATION_MS', 0),

        // Query parameters on db.query spans (values of sensitive columns, e.g. `pin = ?`, are redacted).
        'db_bindings' => env('ELGIOSOFT_LOGGER_DB_BINDINGS', true),

        // Query results on db.query spans: first rows of a SELECT (sensitive columns redacted) or the
        // affected-row count of an UPDATE/DELETE. Turn off where result data must not leave the app.
        'db_results' => env('ELGIOSOFT_LOGGER_DB_RESULTS', true),
        'db_result_rows' => (int) env('ELGIOSOFT_LOGGER_DB_RESULT_ROWS', 10),
        'db_result_max_bytes' => (int) env('ELGIOSOFT_LOGGER_DB_RESULT_MAX_BYTES', 8192),
        'http_client' => true,
        'queue' => true,
        'console' => true,
        'cache' => false,

        'max_spans' => 1000,

        'ignore_paths' => [
            'up',
            'health',
            'telescope*',
            'horizon*',
            '_debugbar*',
            '_ignition*',
        ],

        'ignore_commands' => [
            'queue:*',
            'horizon*',
            'schedule:*',
            'octane:*',
            'reverb:*',
            'serve',
            'tinker',
            'pail',
            'elgiosoft-logger:*',
            'package:discover',
            'vendor:publish',
            'config:*',
            'route:*',
            'view:*',
            'event:*',
            'optimize*',
            'migrate*',
            'list',
            'help',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload capture (off by default)
    |--------------------------------------------------------------------------
    |
    | Keeps HTTP bodies on the request / http.client spans, e.g. to see what a
    | provider sent to a webhook or answered to a payment call. Values of the
    | "redact" keys are masked, credentials and cookies are dropped, binary
    | content is only described and bodies are cut at max_body_bytes.
    |
    */

    'capture' => [
        // Incoming paths whose request headers, query, body and response body are kept,
        // comma-separated route patterns: "webhooks/*,api/callbacks/*".
        'request_paths' => env('ELGIOSOFT_LOGGER_CAPTURE_PATHS', ''),

        // Request and response bodies of outgoing HTTP calls (Laravel Http client and
        // GuzzleMiddleware). These calls are then kept even when the trace is not sampled.
        'http_client' => env('ELGIOSOFT_LOGGER_CAPTURE_HTTP_CLIENT', false),

        'max_body_bytes' => (int) env('ELGIOSOFT_LOGGER_CAPTURE_MAX_BYTES', 16384),
    ],

    // Push the TraceRequests middleware to the front of the global middleware stack.
    'middleware' => [
        'auto' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data protection
    |--------------------------------------------------------------------------
    |
    | send_default_pii adds the authenticated user's email/name and the client IP
    | to events. Explicit ElgioLogger::setUser() data is always sent.
    |
    */

    'send_default_pii' => env('ELGIOSOFT_LOGGER_SEND_DEFAULT_PII', false),

    // Matched case-insensitively, ignoring "_", "-" and spaces, at any depth.
    'redact' => [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'pin',
        'pin_code',
        'otp',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'secret',
        'client_secret',
        'authorization',
        'cookie',
        'card_number',
        'cvv',
        'cvc',
        'api_key',
        'private_key',
        'x_api_key',
    ],

    // Static tags added to every event, e.g. ['region' => 'cm-1'].
    'tags' => [],

    /*
    |--------------------------------------------------------------------------
    | Stack traces
    |--------------------------------------------------------------------------
    */

    // Path prefixes treated as application code. Defaults to base_path().
    'in_app_paths' => [],

    'in_app_exclude' => ['/vendor/', '/storage/framework/'],

    'context_lines' => 5,

    // Where SDK failures are reported (a log channel name). Null → PHP error_log().
    // Never point this at a stack that contains the "elgiosoft" channel.
    'fallback_channel' => env('ELGIOSOFT_LOGGER_FALLBACK_CHANNEL'),

];
