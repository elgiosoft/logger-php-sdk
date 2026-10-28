<?php

declare(strict_types=1);

namespace Elgiosoft\Logger;

use Elgiosoft\Logger\Console\InstallCommand;
use Elgiosoft\Logger\Console\TestCommand;
use Elgiosoft\Logger\Http\Middleware\TraceRequests;
use Elgiosoft\Logger\Integrations\BreadcrumbIntegration;
use Elgiosoft\Logger\Integrations\CacheIntegration;
use Elgiosoft\Logger\Integrations\ConsoleIntegration;
use Elgiosoft\Logger\Integrations\DatabaseIntegration;
use Elgiosoft\Logger\Integrations\HttpClientIntegration;
use Elgiosoft\Logger\Integrations\QueueIntegration;
use Elgiosoft\Logger\Monolog\CreateElgiosoftLogger;
use Elgiosoft\Logger\Serializers\ExceptionSerializer;
use Elgiosoft\Logger\Support\Normalizer;
use Elgiosoft\Logger\Support\BodyCapture;
use Elgiosoft\Logger\Support\Redactor;
use Elgiosoft\Logger\Transport\HttpTransport;
use Elgiosoft\Logger\Transport\QueueTransport;
use Elgiosoft\Logger\Transport\Spool;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/elgiosoft-logger.php', 'elgiosoft-logger');

        // Must happen before the first database connection is made.
        if ($this->capturesQueryResults($this->config($this->app))) {
            DatabaseIntegration::registerConnections();
        }

        $this->app->singleton(HttpTransport::class, function (Application $app): HttpTransport {
            $config = $this->config($app);
            $onFailure = fn (string $message) => $app->make(Client::class)->reportFailure($message);

            // In queue mode the SendBatch job retries by itself: no fallback needed.
            if ($config['transport'] === 'queue') {
                return new HttpTransport($config, $onFailure);
            }

            return new HttpTransport(
                $config,
                $onFailure,
                $config['spool']['enabled'] ? new Spool($config['spool']['path'], $config['spool']['max_files'], $config['spool']['max_age']) : null,
                $this->fallbackQueue($app, $config, $onFailure),
            );
        });

        $this->app->singleton(Client::class, function (Application $app): Client {
            $config = $this->config($app);
            $onFailure = fn (string $message) => $app->make(Client::class)->reportFailure($message);

            $transport = $config['transport'] === 'queue'
                ? new QueueTransport($app->make(BusDispatcher::class), $config, $onFailure)
                : $app->make(HttpTransport::class);

            return new Client(
                $config,
                $transport,
                new ExceptionSerializer(
                    $config['in_app_paths'] ?: [$app->basePath()],
                    (array) $config['in_app_exclude'],
                    (int) $config['context_lines'],
                ),
                new Normalizer,
                new Redactor((array) $config['redact'], ! $config['send_default_pii']),
                $this->userResolver($app, (bool) $config['send_default_pii']),
                $this->failureReporter($app, $config['fallback_channel'] ?? null),
            );
        });

        $this->app->singleton(BodyCapture::class, function (Application $app): BodyCapture {
            $config = $this->config($app);

            return new BodyCapture(new Redactor((array) $config['redact']), $config['capture']['max_body_bytes']);
        });

        $this->app->alias(Client::class, 'elgiosoft-logger');

        $this->callAfterResolving('log', function (LogManager $log): void {
            $log->extend('elgiosoft', fn (Application $app, array $config) => (new CreateElgiosoftLogger)($app, $config));
        });

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler, Application $app): void {
            $this->hookExceptionHandler($handler, $app);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/elgiosoft-logger.php' => $this->app->configPath('elgiosoft-logger.php'),
            ], 'elgiosoft-logger-config');

            $this->commands([TestCommand::class, InstallCommand::class]);
        }

        $config = $this->config($this->app);

        if (! $config['enabled']) {
            return;
        }

        $client = $this->app->make(Client::class);
        $events = $this->app->make(Dispatcher::class);
        $tracing = (bool) $config['tracing']['enabled'];

        $this->registerMiddleware($config);

        $events->listen(RouteMatched::class, function (RouteMatched $event) use ($client): void {
            $context = $client->requestContext();

            if (isset($context['http'])) {
                $context['http']['route'] = '/'.ltrim($event->route->uri(), '/');
                $client->setRequestContext($context);
            }
        });

        (new QueueIntegration($client))->register($events);
        (new BreadcrumbIntegration($client, $this->app->runningInConsole()))->register($events);

        if ($tracing && $config['tracing']['db_queries']) {
            $database = new DatabaseIntegration($client);
            $database->register($events);
            $this->app->instance(DatabaseIntegration::class, $database);
        }

        if ($tracing && $config['tracing']['console']) {
            (new ConsoleIntegration($client))->register($events);
        }

        if ($tracing && $config['tracing']['cache']) {
            (new CacheIntegration($client))->register($events);
        }

        $this->callAfterResolving(HttpFactory::class, function (HttpFactory $factory) use ($client): void {
            (new HttpClientIntegration($client, $this->app->make(BodyCapture::class)))->register($factory);
        });

        $this->app->terminating(function () use ($client): void {
            $client->flush();
            $client->resetRequestState();
        });

        register_shutdown_function(static function () use ($client): void {
            try {
                $client->flush();
            } catch (Throwable) {
                // Never let the SDK break shutdown.
            }
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function registerMiddleware(array $config): void
    {
        if (! $config['middleware']['auto'] || $this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        try {
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(TraceRequests::class)) {
                return;
            }

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(TraceRequests::class);
            }
        } catch (Throwable) {
            // No HTTP kernel (e.g. a console-only app).
        }
    }

    /**
     * Capture exceptions reported through Laravel's handler even when the "elgiosoft" channel is not in the
     * log stack. The handler only calls reportable callbacks for exceptions it reports (dontReport,
     * shouldReport and report() returning false are respected); the Client sends each exception once
     * when the channel then logs it too.
     */
    private function hookExceptionHandler(ExceptionHandler $handler, Application $app): void
    {
        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $exception) use ($handler, $app): void {
            try {
                $config = $this->config($app);

                if (! $config['enabled'] || ! $config['capture_exceptions']) {
                    return;
                }

                $client = $app->make(Client::class);

                if (! $client->isRecording()) {
                    return;
                }

                [$level, $context] = $client->withoutRecording(fn (): array => $this->handlerLevelAndContext($handler, $exception));
                $client->captureReportedException($exception, $context, $level);
            } catch (Throwable) {
                // Never break the application's error reporting.
            }
        });
    }

    /**
     * The log level and context Laravel's handler would log the exception with (its protected
     * mapLogLevel() / buildExceptionContext(), when available).
     *
     * @return array{0: string, 1: array<array-key, mixed>}
     */
    private function handlerLevelAndContext(ExceptionHandler $handler, Throwable $exception): array
    {
        $read = function (Throwable $exception): array {
            $level = method_exists($this, 'mapLogLevel') ? $this->mapLogLevel($exception) : 'error';
            $context = method_exists($this, 'buildExceptionContext') ? $this->buildExceptionContext($exception) : [];

            return [is_string($level) ? $level : 'error', is_array($context) ? $context : []];
        };

        try {
            return \Closure::bind($read, $handler, $handler::class)($exception);
        } catch (Throwable) {
            return ['error', []];
        }
    }

    /**
     * The queue transport used as the fallback of the HTTP modes: only when a queue connection is set
     * explicitly for the SDK (ELGIOSOFT_LOGGER_QUEUE_CONNECTION) and it is a real queue (not "sync").
     *
     * @param  array<string, mixed>  $config
     * @param  \Closure(string): void  $onFailure
     */
    private function fallbackQueue(Application $app, array $config, \Closure $onFailure): ?QueueTransport
    {
        $connection = $config['queue']['connection'] ?? null;

        if (! is_string($connection) || $connection === '') {
            return null;
        }

        $driver = $app['config']->get("queue.connections.{$connection}.driver");

        if ($driver === null || in_array($driver, ['sync', 'null'], true)) {
            return null;
        }

        return new QueueTransport($app->make(BusDispatcher::class), $config, $onFailure);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function capturesQueryResults(array $config): bool
    {
        $tracing = (array) ($config['tracing'] ?? []);

        // The key/enabled state is checked at runtime by every result hand-off (config may be applied
        // after register(), e.g. in tests); the connection subclass is a pass-through when not recording.
        return filter_var($tracing['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)
            && filter_var($tracing['db_queries'] ?? true, FILTER_VALIDATE_BOOLEAN)
            && filter_var($tracing['db_results'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Application $app): array
    {
        $config = (array) $app['config']->get('elgiosoft-logger', []);
        $config['environment'] = $config['environment'] ?: $app->environment();
        $config['service'] = ($config['service'] ?? null) ?: ($app['config']->get('app.name') ?: null);
        $config['server_name'] = $config['server_name'] ?: (gethostname() ?: null);
        $config['enabled'] = filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config['compress'] = filter_var($config['compress'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config['send_default_pii'] = filter_var($config['send_default_pii'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $config['tracing'] = (array) ($config['tracing'] ?? []) + ['enabled' => true, 'requests' => true, 'db_queries' => true, 'http_client' => true, 'queue' => true, 'console' => true, 'cache' => false];
        $config['tracing']['enabled'] = filter_var($config['tracing']['enabled'], FILTER_VALIDATE_BOOLEAN);
        $config['tracing']['db_bindings'] = filter_var($config['tracing']['db_bindings'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config['tracing']['db_results'] = filter_var($config['tracing']['db_results'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config['tracing']['all_requests'] = filter_var($config['tracing']['all_requests'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $capture = (array) ($config['capture'] ?? []);
        $paths = $capture['request_paths'] ?? [];
        $config['capture'] = [
            'request_paths' => array_values(array_filter(array_map('trim', is_string($paths) ? explode(',', $paths) : (array) $paths))),
            'http_client' => filter_var($capture['http_client'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_body_bytes' => max(256, (int) ($capture['max_body_bytes'] ?? 16384)),
        ];
        $config['middleware'] = (array) ($config['middleware'] ?? []) + ['auto' => true];
        $config['capture_exceptions'] = filter_var($config['capture_exceptions'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config['scrub_messages'] = filter_var($config['scrub_messages'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config['logs_sample_rate'] = self::rate($config['logs_sample_rate'] ?? 1.0);
        $config['errors_sample_rate'] = self::rate($config['errors_sample_rate'] ?? 1.0);
        $breadcrumbs = (array) ($config['breadcrumbs'] ?? []);
        $config['breadcrumbs'] = ['max' => max(0, min(100, (int) ($breadcrumbs['max'] ?? 30)))];

        foreach (['logs' => true, 'db_queries' => true, 'http_client' => true, 'cache' => false, 'queue' => true, 'console' => true] as $source => $default) {
            $config['breadcrumbs'][$source] = filter_var($breadcrumbs[$source] ?? $default, FILTER_VALIDATE_BOOLEAN);
        }

        $retry = (array) ($config['retry'] ?? []);
        $config['retry'] = [
            'max_retries' => isset($retry['max_retries']) && $retry['max_retries'] !== '' ? max(0, (int) $retry['max_retries']) : null,
            'budget' => isset($retry['budget']) && $retry['budget'] !== '' ? max(0.0, (float) $retry['budget']) : null,
        ];
        $spool = (array) ($config['spool'] ?? []);
        $config['spool'] = [
            'enabled' => filter_var($spool['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'path' => (string) (($spool['path'] ?? null) ?: $app->storagePath().'/framework/elgiosoft-logger'),
            'max_files' => max(1, (int) ($spool['max_files'] ?? 100)),
            'max_age' => max(60, (int) ($spool['max_age'] ?? 86400)),
            'replay_batches' => max(0, (int) ($spool['replay_batches'] ?? 5)),
        ];
        $config['transport'] = in_array($config['transport'] ?? 'queue', ['queue', 'deferred', 'sync'], true) ? $config['transport'] : 'queue';
        $config['in_app_paths'] = array_values(array_filter((array) ($config['in_app_paths'] ?? [])));
        $config['in_app_exclude'] ??= ['/vendor/'];
        $config['context_lines'] ??= 5;
        $config['redact'] ??= [];

        return $config;
    }

    private static function rate(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 1.0;
    }

    /**
     * @return \Closure(): (array<string, string>|null)
     */
    private function userResolver(Application $app, bool $sendPii): \Closure
    {
        return static function () use ($app, $sendPii): ?array {
            if (! $app->resolved('auth')) {
                return null;
            }

            $guard = $app['auth']->guard();

            if (method_exists($guard, 'hasUser') && ! $guard->hasUser()) {
                return null;
            }

            $user = $guard->user();

            if ($user === null) {
                return null;
            }

            $data = ['id' => (string) $user->getAuthIdentifier()];

            if ($sendPii) {
                foreach (['email' => 'email', 'username' => 'name'] as $key => $attribute) {
                    $value = $user->{$attribute} ?? null;

                    if (is_scalar($value) && $value !== '') {
                        $data[$key] = (string) $value;
                    }
                }
            }

            return $data;
        };
    }

    /**
     * @return \Closure(string): void
     */
    private function failureReporter(Application $app, ?string $channel): \Closure
    {
        return static function (string $line) use ($app, $channel): void {
            if ($channel !== null && $channel !== '') {
                $app['log']->channel($channel)->warning($line);

                return;
            }

            error_log($line);
        };
    }
}
