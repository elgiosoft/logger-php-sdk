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
    | Error capture and sampling
    |--------------------------------------------------------------------------
    |
    | capture_exceptions hooks into Laravel's exception handler, so every reported
    | exception is sent even when the "elgiosoft" channel is not in your log stack.
    | dontReport / shouldReport are respected, and an exception that also reaches
    | the channel is sent once.
    |
    | logs_sample_rate applies to logs below "error", errors_sample_rate to "error"
    | and above (1.0 = keep all, 0.0 = keep none). They are independent of
    | tracing.sample_rate: a kept log may belong to an unsampled trace and the
    | reverse. Sampled-away logs still appear as breadcrumbs.
    |
    */

    'capture_exceptions' => env('ELGIOSOFT_LOGGER_CAPTURE_EXCEPTIONS', true),

    'logs_sample_rate' => (float) env('ELGIOSOFT_LOGGER_LOGS_SAMPLE_RATE', 1.0),

    'errors_sample_rate' => (float) env('ELGIOSOFT_LOGGER_ERRORS_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | The last "max" events of the current request / queued job / command, sent
    | as context.breadcrumbs (oldest first) on every log of level "error" and up.
    | Each is {timestamp, category, message, level, data?}; data is redacted and
    | messages scrubbed like logs. 0 turns breadcrumbs off.
    |
    */

    'breadcrumbs' => [
        'max' => (int) env('ELGIOSOFT_LOGGER_MAX_BREADCRUMBS', 30),

        // Every log record through Laravel's logger, including those below the channel level.
        'logs' => true,

        // The SQL; its bindings only when tracing.db_bindings is on (sensitive columns redacted).
        'db_queries' => true,

        // Method, URL without its query string, status.
        'http_client' => true,

        // Cache hits and misses (noisy, off by default).
        'cache' => false,

        'queue' => true,
        'console' => true,
    ],

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

    // Also used by deferred / sync as the fallback for failed batches when the connection is set.
    'queue' => [
        'connection' => env('ELGIOSOFT_LOGGER_QUEUE_CONNECTION'),
        'queue' => env('ELGIOSOFT_LOGGER_QUEUE', 'default'),
        'tries' => (int) env('ELGIOSOFT_LOGGER_QUEUE_TRIES', 3),
    ],

    // Seconds. The SDK never waits longer than this for one request to the collector.
    'timeout' => (float) env('ELGIOSOFT_LOGGER_TIMEOUT', 2),

    // deferred / sync: connection errors, timeouts, 5xx, 408 and 429 are retried with exponential
    // backoff and jitter while both limits allow (never sooner than a 429's Retry-After). Null keeps
    // the mode's default: deferred 2 retries within 3 s, sync no retry (within 0.5 s if you set some).
    // Queue mode retries by releasing the job instead (queue.tries).
    'retry' => [
        'max_retries' => env('ELGIOSOFT_LOGGER_RETRIES'),
        'budget' => env('ELGIOSOFT_LOGGER_RETRY_BUDGET'),
    ],

    // deferred / sync: where a batch goes when it still could not be delivered. With
    // ELGIOSOFT_LOGGER_QUEUE_CONNECTION set (to a non-sync queue) it becomes a SendBatch job; otherwise
    // it is written to this directory and resent by the next successful send of any process
    // (replay_batches at a time). Batches older than max_age seconds or beyond max_files are dropped.
    'spool' => [
        'enabled' => env('ELGIOSOFT_LOGGER_SPOOL', true),
        'path' => env('ELGIOSOFT_LOGGER_SPOOL_PATH'), // default: storage/framework/elgiosoft-logger
        'max_files' => (int) env('ELGIOSOFT_LOGGER_SPOOL_MAX_FILES', 100),
        'max_age' => (int) env('ELGIOSOFT_LOGGER_SPOOL_MAX_AGE', 86400),
        'replay_batches' => 5,
    ],

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

        // Query results on db.query spans: the row count of a SELECT or the affected-row count of an
        // UPDATE/DELETE. The rows themselves are only sent when db_result_rows is above 0 (sensitive
        // columns redacted by name); they hold real data (tokens, keys, emails), so keep it at 0 in production.
        'db_results' => env('ELGIOSOFT_LOGGER_DB_RESULTS', true),
        'db_result_rows' => (int) env('ELGIOSOFT_LOGGER_DB_RESULT_ROWS', 0),
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

    // Mask secrets in log messages, exception messages and breadcrumbs: values of the "redact" keys
    // written as key=value / key: value / "key": "value" / <key>value</key>, Bearer/Basic credentials,
    // Luhn-valid card numbers and, unless send_default_pii is on, e-mail addresses.
    'scrub_messages' => env('ELGIOSOFT_LOGGER_SCRUB_MESSAGES', true),

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
