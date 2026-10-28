# elgiosoft/logger

Laravel client for **Elgiosoft Logger**, our central service for logs, error tracking and
distributed tracing. Add it to any Laravel app and the app will:

- ship normal `Log::...` calls to the collector, with structured context and sensitive keys redacted
- report exceptions with full stack traces (source context for your own code) so the
  collector can group them into issues
- trace every HTTP request, DB query, outgoing HTTP call and queued job, and propagate the
  trace to other Elgiosoft services with the W3C `traceparent` header
- keep working when the collector is down. The SDK never throws into your app and never blocks a request.

Supports PHP 8.1+ and Laravel 9, 10, 11, 12 and 13 (Monolog 2 or 3). On Laravel 9, outgoing HTTP calls are not traced (the HTTP client has no global middleware before Laravel 10); everything else works.

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
ELGIOSOFT_LOGGER_TRANSPORT=deferred                                  # deferred (recommended) | queue | sync
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
        // 'channels' => ['single', 'elgiosoft'],                       // Laravel 9 and 10
        'ignore_exceptions' => false,
    ],

    'elgiosoft' => [
        'driver' => 'elgiosoft',
        'level' => env('ELGIOSOFT_LOGGER_LEVEL', 'debug'),
    ],
    // ...
],
```

That's all you need. Exceptions reported by Laravel's handler are also captured directly (see
*Errors* below), so they arrive with their stack trace even if the `elgiosoft` channel is missing
from the stack.

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

## 3b. Errors: exception capture, breadcrumbs, sampling

**Exception capture.** The SDK registers a `reportable()` callback on Laravel's exception handler
(works with `bootstrap/app.php` `withExceptions()` on Laravel 11+ and with `App\Exceptions\Handler`
before). Every exception the handler reports is sent with its stack trace, its handler log level
(`$exceptions->level(...)`) and context, even when the `elgiosoft` channel is not in your log stack.
`dontReport`, `shouldReport`, `report()` returning `false` and earlier `reportable` callbacks that stop
reporting are respected. When the channel *is* in the stack, the handler's log record for the same
exception is merged into that event: each exception object is sent once. An exception you already
sent with `ElgioLogger::captureException()` isn't sent again when it is reported.
Turn the hook off with `ELGIOSOFT_LOGGER_CAPTURE_EXCEPTIONS=false`.

**Breadcrumbs.** Every log of level `error` and above (and every captured exception) carries
`context.breadcrumbs`: the last 30 things that happened in the current request, queued job or artisan
command, oldest first:

```json
{"timestamp": "2026-10-28T09:12:03.120391Z", "category": "query", "level": "info",
 "message": "select * from wallets where user_id = ?", "data": {"connection": "mysql", "duration_ms": 1.2, "params": [{"name": "user_id", "value": 7}]}}
```

| Category | Recorded | Switch (`breadcrumbs.*`) |
|---|---|---|
| `log` | Every `Log::` record (also those below the channel's level, and when the channel is not in the stack) and `ElgioLogger::log()/event()` | `logs` |
| `query` | The SQL; bindings only when `ELGIOSOFT_LOGGER_DB_BINDINGS` is on (sensitive columns redacted) | `db_queries` |
| `http` | Outgoing `Http::`/`GuzzleMiddleware` calls: method, URL without query string, status | `http_client` |
| `queue` | The job starting (its name, queue, connection, attempt) | `queue` |
| `console` | The artisan command starting | `console` |
| `cache` | Cache hits and misses (off by default) | `cache` |

The buffer is emptied when a request starts, when a job starts (the dispatcher's buffer comes back after a
synchronous job) and when a top-level artisan command starts. Data is redacted with the `redact` keys and
messages are scrubbed like log messages. Add your own with
`ElgioLogger::addBreadcrumb('payment', 'Charge sent to MTN', 'info', ['amount' => 5000])`.
`ELGIOSOFT_LOGGER_MAX_BREADCRUMBS` sets the size (0 turns breadcrumbs off, at most 100).

**Sampling logs and errors.** `ELGIOSOFT_LOGGER_LOGS_SAMPLE_RATE` (default `1.0`) keeps that share of logs
below `error`; `ELGIOSOFT_LOGGER_ERRORS_SAMPLE_RATE` (default `1.0`) the share of `error` and above. Each
log is decided on its own, independently of `ELGIOSOFT_LOGGER_SAMPLE_RATE` (tracing): a kept log may
belong to an unsampled trace and the reverse. Sampled-away logs are still recorded as breadcrumbs.

## 4. Tracing

Everything below is automatic once the package is installed:

| What | Span `op` | Notes |
|------|-----------|-------|
| Incoming HTTP request | `http.server` | Continues an incoming `traceparent`, otherwise starts a trace. Name `GET /api/withdrawals/{id}`. Adds `X-Trace-Id` and `X-Request-Id` response headers. 5xx marks the span `error`. |
| DB queries | `db.query` | SQL + **parameters** (`db.params`, values of sensitive columns such as `pin = ?` redacted) + **result**: the row count of a SELECT (`db.rows`) or `db.rows_affected`. The rows themselves (`db.result`, sensitive columns redacted) are opt-in with `ELGIOSOFT_LOGGER_DB_RESULT_ROWS` above 0, default 0: they hold real data such as tokens, keys and emails, so keep them out of production. Switches: `ELGIOSOFT_LOGGER_DB_BINDINGS`, `ELGIOSOFT_LOGGER_DB_RESULTS`, `ELGIOSOFT_LOGGER_DB_RESULT_ROWS` (0), `ELGIOSOFT_LOGGER_DB_RESULT_MAX_BYTES` (8192). `tracing.db_min_duration_ms` hides fast queries. Results use a thin subclass of Laravel's connection, skipped for any driver another package already customised. |
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

Every request is still listed on the Requests page: an unsampled request sends its own summary
(method, path, route, status, duration, user agent) marked `sampled: false`, without its child spans.
Set `ELGIOSOFT_LOGGER_ALL_REQUESTS=false` to send nothing for unsampled requests. Paths in
`tracing.ignore_paths` (`up`, `health`, …) are never recorded.

The `TraceRequests` middleware is prepended to the global middleware stack automatically. To
place it yourself, set `middleware.auto` to `false` and add
`Elgiosoft\Logger\Http\Middleware\TraceRequests::class` where you want it.

## 5. Transports

| `ELGIOSOFT_LOGGER_TRANSPORT` | What happens | Use for |
|---|---|---|
| `deferred` (recommended) | Buffered events are POSTed after the response has been sent (`terminating`), and after each queued job. With `ELGIOSOFT_LOGGER_TIMEOUT=1` a slow collector costs at most ~1 s of a PHP process, never a queue. | Production. |
| `queue` (default) | Buffered events become one `SendBatch` job on **your** queue (Redis). A worker POSTs it to the collector. | Only with a dedicated queue and worker. |
| `sync` | Every event is POSTed immediately. | Tests, debugging, one-off CLI scripts. |

The buffer is flushed when:

- the request terminates
- a queued job finishes
- an artisan command finishes
- the worker loops
- the buffer reaches `max_buffer` (200) events
- the PHP process shuts down

Recommended production settings:

```dotenv
ELGIOSOFT_LOGGER_TRANSPORT=deferred
ELGIOSOFT_LOGGER_TIMEOUT=1
ELGIOSOFT_LOGGER_MAX_BUFFER=1000      # flushing mid-request would make that user wait
ELGIOSOFT_LOGGER_SAMPLE_RATE=0.1
ELGIOSOFT_LOGGER_DB_RESULTS=false
ELGIOSOFT_LOGGER_DB_BINDINGS=false
ELGIOSOFT_LOGGER_LEVEL=info
```

**Delivery failures (deferred and sync).** Connection errors, timeouts, `5xx`, `408` and `429` are
retried with exponential backoff and jitter within a small budget: `deferred` retries twice within 3 s
(it runs after the response), `sync` doesn't retry (it must stay fast). A `429`'s `Retry-After` is
honoured: the SDK never retries sooner, and if the wait is longer than the budget it goes straight to
the fallback. A `413` (batch too large) splits the batch in half and sends each half. Other `4xx`
answers (bad key, invalid payload) are reported and the batch dropped.

A batch that still could not be delivered goes to the fallback:

- the queue, as a `SendBatch` job, **only** when `ELGIOSOFT_LOGGER_QUEUE_CONNECTION` is set to a real
  (non-`sync`) connection;
- otherwise the **local spool**: one JSON file per batch in `storage/framework/elgiosoft-logger`
  (`ELGIOSOFT_LOGGER_SPOOL_PATH`). The next successful send of any PHP process resends up to 5 spooled
  batches. At most `ELGIOSOFT_LOGGER_SPOOL_MAX_FILES` (100) files are kept and files older than
  `ELGIOSOFT_LOGGER_SPOOL_MAX_AGE` (86400 s) are dropped. `ELGIOSOFT_LOGGER_SPOOL=false` drops the batch
  instead. Every fallback is reported to the fallback channel / `error_log`.

Tune the retries with `ELGIOSOFT_LOGGER_RETRIES` and `ELGIOSOFT_LOGGER_RETRY_BUDGET` (seconds).

Queue mode notes (a job per request, per job and per 200 queries — on a busy app this floods the queue; on
2026-10-08 it delayed Yankap's payment jobs by an hour):

- Run a worker for the queue: `php artisan queue:work redis --queue=logs,default`.
- If the collector is unreachable or answers `5xx`/`408`, `SendBatch` releases itself with backoff and
  jitter (10–20 s, then 20–40 s, …); on a `429` it is released for the `Retry-After` delay. After
  `ELGIOSOFT_LOGGER_QUEUE_TRIES` (default 3) attempts the batch is dropped. A `413` splits it into two
  jobs; other `4xx` answers drop it at once. It never throws, because a thrown exception would be logged
  and generate yet another batch.
- If you use Horizon, give the `logs` queue its own small supervisor.

## 5b. Payload capture (webhooks, provider calls)

Off by default. Useful to see exactly what a payment provider sent to a webhook, or what it answered:

```dotenv
ELGIOSOFT_LOGGER_CAPTURE_PATHS="webhooks/*,api/callbacks/*"   # incoming: headers, query, request + response body
ELGIOSOFT_LOGGER_CAPTURE_HTTP_CLIENT=true                     # outgoing: request + response body of every call
ELGIOSOFT_LOGGER_CAPTURE_MAX_BYTES=16384
```

- Incoming captures go on the request's `http.server` span (`http.request.headers`, `http.request.query`,
  `http.request.body`, `http.response.body`); outgoing ones on `http.client` spans, which are then kept even when the
  trace isn't sampled. The dashboard shows them as "Request & response" on the span.
- JSON and form bodies are kept as objects, so the `redact` keys are masked at any depth; XML and text bodies get
  `key=value`, `"key": "value"` and `<key>value</key>` masked. Cookies, `Authorization` and other credential headers
  are dropped. Binary bodies are only described; anything above the limit is cut.
- Phone numbers, amounts and names are **not** masked — only enable capture where you need those payloads.
- Outgoing capture needs Laravel 10+ for the HTTP client; `GuzzleMiddleware` works everywhere.

## 6. Data protection

- Values of keys like `password`, `pin`, `otp`, `token`, `secret`, `authorization`, `card_number`,
  `cvv`, `api_key`... are replaced with `[Filtered]` at any depth. The match ignores case and
  `_`/`-`, so `cardNumber` matches too. Extend the list in `redact`.
- By default request bodies, headers, cookies and query strings are never sent, and URLs are sent without their
  query string. Payload capture (below) changes that only for the paths and calls you choose.
- The client IP and the user's email/name are only sent with `send_default_pii=true`.
- Log messages, exception messages and breadcrumb messages are scrubbed (`ELGIOSOFT_LOGGER_SCRUB_MESSAGES`,
  default `true`): values of the `redact` keys written as `key=value`, `key: value`, `"key": "value"` or
  `<key>value</key>` (SQL placeholders such as `pin = ?` are left alone), `Bearer`/`Basic` credentials,
  card numbers (13–19 digits starting with 2–6, spaces/dashes allowed, Luhn-checked) and, unless
  `send_default_pii` is on, e-mail addresses. Context values are protected by key, as above.

### New configuration keys

| Key | Env var | Default |
|---|---|---|
| `capture_exceptions` | `ELGIOSOFT_LOGGER_CAPTURE_EXCEPTIONS` | `true` |
| `logs_sample_rate` | `ELGIOSOFT_LOGGER_LOGS_SAMPLE_RATE` | `1.0` |
| `errors_sample_rate` | `ELGIOSOFT_LOGGER_ERRORS_SAMPLE_RATE` | `1.0` |
| `breadcrumbs.max` | `ELGIOSOFT_LOGGER_MAX_BREADCRUMBS` | `30` |
| `breadcrumbs.logs` / `db_queries` / `http_client` / `queue` / `console` / `cache` | – | `true` (cache `false`) |
| `retry.max_retries` | `ELGIOSOFT_LOGGER_RETRIES` | deferred `2`, sync `0` |
| `retry.budget` | `ELGIOSOFT_LOGGER_RETRY_BUDGET` | deferred `3` s, sync `0.5` s |
| `spool.enabled` | `ELGIOSOFT_LOGGER_SPOOL` | `true` |
| `spool.path` | `ELGIOSOFT_LOGGER_SPOOL_PATH` | `storage/framework/elgiosoft-logger` |
| `spool.max_files` | `ELGIOSOFT_LOGGER_SPOOL_MAX_FILES` | `100` |
| `spool.max_age` | `ELGIOSOFT_LOGGER_SPOOL_MAX_AGE` | `86400` |
| `spool.replay_batches` | – | `5` |
| `scrub_messages` | `ELGIOSOFT_LOGGER_SCRUB_MESSAGES` | `true` |

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| Nothing arrives | `php artisan elgiosoft-logger:test`. Is the key set? Is `config:cache` stale? (`php artisan config:clear`) |
| Works in `test` but not in the app | In `queue` mode a worker must consume `ELGIOSOFT_LOGGER_QUEUE`. Is the channel in your `stack`? |
| `401 Invalid API key` | The key was revoked or belongs to another environment. Create a new one in the dashboard. |
| SDK errors | Written to PHP's `error_log` with the prefix `[elgiosoft-logger]`, or to `ELGIOSOFT_LOGGER_FALLBACK_CHANNEL`. Never point the fallback at a stack that contains `elgiosoft`. |
| Files piling up in `storage/framework/elgiosoft-logger` | The collector has been unreachable or rate limiting; they are resent once it answers again. Check the fallback channel for the reason. |
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
