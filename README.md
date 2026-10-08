# elgiosoft/logger

Laravel client for **Elgiosoft Logger**, our central service for logs, error tracking and
distributed tracing. Add it to any Laravel app and the app will:

- ship normal `Log::...` calls to the collector, with structured context and sensitive keys redacted
- report exceptions with full stack traces (source context for your own code) so the
  collector can group them into issues
- trace every HTTP request, DB query, outgoing HTTP call and queued job, and propagate the
  trace to other Elgiosoft services with the W3C `traceparent` header
- keep working when the collector is down. The SDK never throws into your app and never blocks a request.

Supports PHP 8.1+ and Laravel 10, 11, 12 and 13 (Monolog 3).

---

## 1. Install

The package isn't on Packagist yet. Add a path repository to the app's `composer.json`
(the path is relative to the app):

```json
"repositories": [
    { "type": "path", "url": "../packages/logger/sdk-php" }
]
```

```bash
composer require elgiosoft/logger:@dev
php artisan elgiosoft-logger:install   # publishes config/elgiosoft-logger.php and prints the steps
```

The service provider and the `ElgioLogger` facade are auto-discovered.

## 2. Configure

`.env`:

```dotenv
# ELGIOSOFT_LOGGER_ENDPOINT=https://elgiologs.com   # default; set only for a local/self-hosted collector
ELGIOSOFT_LOGGER_KEY=elg_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx   # project key, or one account key shared by all apps
ELGIOSOFT_LOGGER_SERVICE=checkout      # optional – defaults to APP_NAME; picks the project when using an account key
ELGIOSOFT_LOGGER_TRANSPORT=queue                                     # queue | deferred | sync
# optional
ELGIOSOFT_LOGGER_ENVIRONMENT=production      # defaults to APP_ENV
ELGIOSOFT_LOGGER_RELEASE=2026.10.08-1        # e.g. the git sha / deploy tag
ELGIOSOFT_LOGGER_QUEUE_CONNECTION=redis
ELGIOSOFT_LOGGER_QUEUE=logs
ELGIOSOFT_LOGGER_SAMPLE_RATE=1.0
```

The SDK does nothing until **both** a key and an endpoint are set, so it's safe to install
everywhere (local, CI, ...).

`config/logging.php`: add the channel and include it in your stack:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => explode(',', env('LOG_STACK', 'single,elgiosoft')), // Laravel 11+
        // 'channels' => ['single', 'elgiosoft'],                       // Laravel 10
        'ignore_exceptions' => false,
    ],

    'elgiosoft' => [
        'driver' => 'elgiosoft',
        'level' => env('ELGIOSOFT_LOGGER_LEVEL', 'debug'),
    ],
    // ...
],
```

That's all you need. Exceptions reported by Laravel's handler go through the log stack, so they
arrive with their stack trace.

Check the setup:

```bash
php artisan elgiosoft-logger:test
```

This validates the key (`GET /api/v1/ping`) and sends a test log, a test exception and a small
trace directly to the collector, skipping the queue. It prints the trace id; search `trace:<id>` in the dashboard.

## 3. Usage

### Plain Laravel logging

```php
Log::error('Payout failed', [
    'event' => 'payment.failed',          // lifted to the event's "event" field
    'transaction_id' => $transaction->id, // lifted to "transaction_id"
    'provider' => 'mtn',                  // everything else is searchable context: @provider:mtn
    'amount' => 25000,
    'currency' => 'XAF',
    'tags' => ['provider' => 'mtn'],      // low-cardinality facets
]);

Log::error($e->getMessage(), ['exception' => $e]); // full stack trace + previous chain
```

The keys `event`, `transaction_id`, `request_id`, `fingerprint` (array of strings) and `tags` are
lifted out of the context. Everything else stays in `context`.

### The facade

```php
use Elgiosoft\Logger\Facades\ElgioLogger;

ElgioLogger::setUser(['id' => $user->id, 'email' => $user->email]);
ElgioLogger::setTag('country', 'CM');
ElgioLogger::setContext('wallet', ['id' => $wallet->id, 'currency' => 'XAF']);

ElgioLogger::event('withdrawal.pending', 'Withdrawal pending for more than 5 minutes', [
    'transaction_id' => $tx->id,
    'provider' => 'orange',
    'amount' => 50000,
], 'warning');

ElgioLogger::captureException($e, ['transaction_id' => $tx->id]);

// Custom grouping for an error
Log::error('Provider unavailable', ['fingerprint' => ['provider-down', 'mtn']]);

$traceId = ElgioLogger::traceId(); // show it to support or add it to API error responses
ElgioLogger::flush();              // rarely needed; flushing is automatic
```

Scope (user, tags, contexts) lasts for the current request, or for the current job in a worker.
When nothing is set, the authenticated user's id is attached automatically. Email and name are
only attached with `ELGIOSOFT_LOGGER_SEND_DEFAULT_PII=true`.

## 4. Tracing

Everything below is automatic once the package is installed:

| What | Span `op` | Notes |
|------|-----------|-------|
| Incoming HTTP request | `http.server` | Continues an incoming `traceparent`, otherwise starts a trace. Name `GET /api/withdrawals/{id}`. Adds `X-Trace-Id` and `X-Request-Id` response headers. 5xx marks the span `error`. |
| DB queries | `db.query` | SQL + **parameters** (`db.params`, values of sensitive columns such as `pin = ?` redacted) + **result**: first rows of a SELECT (`db.rows`, `db.result`, sensitive columns redacted) or `db.rows_affected`. Switches: `ELGIOSOFT_LOGGER_DB_BINDINGS`, `ELGIOSOFT_LOGGER_DB_RESULTS`, `ELGIOSOFT_LOGGER_DB_RESULT_ROWS` (10), `ELGIOSOFT_LOGGER_DB_RESULT_MAX_BYTES` (8192). `tracing.db_min_duration_ms` hides fast queries. Results use a thin subclass of Laravel's connection, skipped for any driver another package already customised. |
| Laravel HTTP client (`Http::`) | `http.client` | Injects `traceparent`, so if the callee also uses this SDK (e.g. storefront → payments API) it joins the same trace. Raw Guzzle/cURL: see *Propagating a trace by hand*. |
| Dispatching a job | `queue.publish` | The trace context travels inside the job payload. |
| Running a job | `queue.job` | Continues the dispatching trace, even on another server, and flushes when the job ends. |
| Artisan commands | `console.command` | Long-running commands (`queue:*`, `schedule:*`, `horizon`...) are ignored. |
| Cache | `cache.*` | Off by default (`tracing.cache`). |

Every log written while a span is open carries that span's `trace_id` and `span_id`, so the
dashboard's trace view shows the waterfall and the logs together.

Manual spans:

```php
$result = ElgioLogger::trace('payout.process', function (Span $span) use ($payout) {
    $span->setAttribute('provider', 'mtn');
    return $this->gateway->payout($payout); // an exception marks the span "error" and is rethrown
});

$span = ElgioLogger::startSpan('pdf.render', 'function', ['pages' => 12]);
// ...
$span->finish();
```

### Propagating a trace by hand

`Http::` calls carry the trace automatically. Anything else (raw Guzzle, cURL, a Node service, a message
broker) needs the header added yourself:

```php
// Outgoing: plain Guzzle (wrap it in a span so the call shows up in the waterfall)
ElgioLogger::trace('POST wallet-service/debit', fn () => $guzzle->post($url, [
    'headers' => ElgioLogger::traceHeaders(),   // ['traceparent' => '00-<trace>-<span>-01']
    'json' => $payload,
]), 'http.client');

// Raw Guzzle clients you construct yourself (or inside another SDK): push the middleware once and
// every request carries the trace and gets an http.client span – no-op when the logger isn't active.
$stack = \GuzzleHttp\HandlerStack::create();
$stack->push(\Elgiosoft\Logger\GuzzleMiddleware::create(), 'elgiosoft_logger');
$client = new \GuzzleHttp\Client(['handler' => $stack, 'base_uri' => $baseUrl]);

// Or just the value, e.g. for curl_setopt or a message attribute
$value = ElgioLogger::traceparent();            // null when the SDK is disabled

// Incoming outside HTTP (queue consumer, broker message, cron entry point)
ElgioLogger::continueTrace($message->headers['traceparent'] ?? null);
```

A non-Laravel service joins the trace by reading the incoming `traceparent` header and sending its logs with
the same `trace_id` (32 hex) and its own span ids — see `INGEST_API.md`. Searching `trace:<id>` in the
dashboard (with "All projects" selected) then returns the logs of every service involved.

Things that start a **new** trace: incoming webhooks from third parties (payment providers, Stripe, MTN, Orange…)
and any hop that doesn't forward the header. Log a business id such as `transaction_id` on both sides and
search `transaction:<id>` to stitch those together.

Sampling: `ELGIOSOFT_LOGGER_SAMPLE_RATE=0.2` records spans for 20% of new traces. Logs are
always sent and always carry the trace id. An upstream decision received in `traceparent` is respected.

The `TraceRequests` middleware is prepended to the global middleware stack automatically. To
place it yourself, set `middleware.auto` to `false` and add
`Elgiosoft\Logger\Http\Middleware\TraceRequests::class` where you want it.

## 5. Transports

| `ELGIOSOFT_LOGGER_TRANSPORT` | What happens | Use for |
|---|---|---|
| `queue` (default) | Buffered events become one `SendBatch` job on **your** queue (Redis). A worker POSTs it to the collector. | Production: requests never wait on the network. |
| `deferred` | Buffered events are POSTed after the response has been sent (`terminating`). | Apps without a queue worker. |
| `sync` | Every event is POSTed immediately. | Tests, debugging, one-off CLI scripts. |

The buffer is flushed when:

- the request terminates
- a queued job finishes
- an artisan command finishes
- the worker loops
- the buffer reaches `max_buffer` (200) events
- the PHP process shuts down

Queue mode notes:

- Run a worker for the queue: `php artisan queue:work redis --queue=logs,default`.
- If the collector is unreachable, `SendBatch` releases itself with backoff
  (`ELGIOSOFT_LOGGER_QUEUE_TRIES`, default 3) and then drops the batch. It never throws, because a
  thrown exception would be logged and generate yet another batch.
- If you use Horizon, give the `logs` queue its own small supervisor.

## 6. Data protection

- Values of keys like `password`, `pin`, `otp`, `token`, `secret`, `authorization`, `card_number`,
  `cvv`, `api_key`... are replaced with `[Filtered]` at any depth. The match ignores case and
  `_`/`-`, so `cardNumber` matches too. Extend the list in `redact`.
- Request bodies, headers, cookies and query strings are never sent. URLs are sent without their query string.
- The client IP and the user's email/name are only sent with `send_default_pii=true`.

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| Nothing arrives | `php artisan elgiosoft-logger:test`. Is the key set? Is `config:cache` stale? (`php artisan config:clear`) |
| Works in `test` but not in the app | In `queue` mode a worker must consume `ELGIOSOFT_LOGGER_QUEUE`. Is the channel in your `stack`? |
| `401 Invalid API key` | The key was revoked or belongs to another environment. Create a new one in the dashboard. |
| SDK errors | Written to PHP's `error_log` with the prefix `[elgiosoft-logger]`, or to `ELGIOSOFT_LOGGER_FALLBACK_CHANNEL`. Never point the fallback at a stack that contains `elgiosoft`. |
| Too many `db.query` spans | Raise `ELGIOSOFT_LOGGER_DB_MIN_DURATION_MS` or set `tracing.db_queries` to `false`. |
| Disable everywhere quickly | `ELGIOSOFT_LOGGER_ENABLED=false` |

## Wire format

The SDK speaks the collector's ingestion contract (`POST /api/v1/ingest`), documented in
`logger/docs/INGEST_API.md`.

## Development

```bash
composer install
vendor/bin/phpunit
```
