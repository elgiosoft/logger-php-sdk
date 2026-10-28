<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\LoggerServiceProvider;
use Elgiosoft\Logger\Transport\HttpTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const ENDPOINT = 'https://logger.test';

    public const KEY = 'elg_test_0123456789abcdef0123456789abcdef0123';

    /**
     * Delays the HTTP transport asked to sleep for between retries (nothing really sleeps in tests).
     *
     * @var list<float>
     */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        $this->sleeps = [];
        HttpTransport::sleepUsing(function (float $seconds): void {
            $this->sleeps[] = $seconds;
        });
        $this->clearSpool();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        HttpTransport::sleepUsing(null);
        $this->clearSpool();
    }

    protected static function spoolPath(): string
    {
        return sys_get_temp_dir().'/elgiosoft-logger-tests-'.getmypid();
    }

    protected function clearSpool(): void
    {
        foreach (glob(self::spoolPath().'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(self::spoolPath());
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('elgiosoft-logger.endpoint', self::ENDPOINT);
        $app['config']->set('elgiosoft-logger.key', self::KEY);
        $app['config']->set('elgiosoft-logger.environment', 'testing');
        $app['config']->set('elgiosoft-logger.release', '1.2.3');
        $app['config']->set('elgiosoft-logger.server_name', 'test-host');
        $app['config']->set('elgiosoft-logger.transport', 'deferred');
        $app['config']->set('elgiosoft-logger.compress', false);
        $app['config']->set('elgiosoft-logger.in_app_paths', [dirname(__DIR__)]);
        $app['config']->set('elgiosoft-logger.in_app_exclude', ['/vendor/']);
        $app['config']->set('elgiosoft-logger.fallback_channel', 'fallback');
        $app['config']->set('elgiosoft-logger.spool.path', self::spoolPath());

        $app['config']->set('logging.default', 'elgiosoft');
        $app['config']->set('logging.channels.elgiosoft', ['driver' => 'elgiosoft', 'level' => 'debug']);
        $app['config']->set('logging.channels.fallback', ['driver' => 'monolog', 'handler' => TestHandler::class]);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
    }

    protected function client(): Client
    {
        return $this->app->make(Client::class);
    }

    protected function fakeCollector(int $status = 202): void
    {
        Http::fake([
            self::ENDPOINT.'/*' => Http::response(['accepted' => ['logs' => 1, 'spans' => 0], 'rejected' => 0], $status),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * Every envelope POSTed to the collector so far.
     *
     * @return list<array<string, mixed>>
     */
    protected function sentEnvelopes(): array
    {
        return Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), self::ENDPOINT.'/api/v1/ingest'))
            ->map(function (array $pair): array {
                [$request] = $pair;
                $body = $request->body();

                if ($request->hasHeader('Content-Encoding', 'gzip')) {
                    $body = (string) gzdecode($body);
                }

                return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sentLogs(): array
    {
        return array_merge([], ...array_map(fn (array $envelope): array => $envelope['logs'] ?? [], $this->sentEnvelopes()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sentSpans(): array
    {
        return array_merge([], ...array_map(fn (array $envelope): array => $envelope['spans'] ?? [], $this->sentEnvelopes()));
    }

    protected function fallbackHandler(): TestHandler
    {
        /** @var \Monolog\Logger $logger */
        $logger = $this->app['log']->channel('fallback')->getLogger();

        /** @var TestHandler $handler */
        $handler = $logger->getHandlers()[0];

        return $handler;
    }
}
