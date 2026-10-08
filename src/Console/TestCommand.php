<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Console;

use Elgiosoft\Logger\Client;
use Elgiosoft\Logger\Transport\HttpTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Sends a log, an exception and a small trace straight to the collector (bypassing the queue).
 */
final class TestCommand extends Command
{
    protected $signature = 'elgiosoft-logger:test';

    protected $description = 'Send a test log, exception and trace to the Elgiosoft Logger collector';

    public function handle(Client $client, HttpTransport $transport): int
    {
        $config = $client->config();

        $this->components->twoColumnDetail('Endpoint', (string) ($config['endpoint'] ?? '–'));
        $this->components->twoColumnDetail('Key', empty($config['key']) ? '<fg=red>missing</>' : substr((string) $config['key'], 0, 8).'…');
        $this->components->twoColumnDetail('Service', (string) ($config['service'] ?? '–'));
        $this->components->twoColumnDetail('Environment', (string) ($config['environment'] ?? '–'));
        $this->components->twoColumnDetail('Transport', (string) ($config['transport'] ?? '–'));

        if (! $client->isEnabled()) {
            $this->components->error('The SDK is disabled: set ELGIOSOFT_LOGGER_KEY and ELGIOSOFT_LOGGER_ENDPOINT (and ELGIOSOFT_LOGGER_ENABLED=true).');

            return self::FAILURE;
        }

        try {
            $ping = $client->withoutRecording(fn () => Http::withToken((string) $config['key'])
                ->acceptJson()
                ->timeout(5)
                ->get(rtrim((string) $config['endpoint'], '/').'/api/v1/ping', array_filter(['service' => $config['service'] ?? null])));

            if (! $ping->successful()) {
                $this->components->error(sprintf('Ping failed with HTTP %d: %s', $ping->status(), mb_substr($ping->body(), 0, 200)));

                return self::FAILURE;
            }

            $this->components->info(($ping->json('scope') === 'account' ? 'Account key accepted; events go to project "' : 'Key accepted for project "').($ping->json('project') ?? '?').'".');
        } catch (Throwable $exception) {
            $this->components->error('Could not reach the collector: '.$exception->getMessage());

            return self::FAILURE;
        }

        $tracer = $client->tracer();
        $tracer->startTrace();

        $client->trace('elgiosoft-logger:test', function () use ($client): void {
            $client->event('logger.test', 'Hello from elgiosoft-logger:test 👋', ['sdk' => Client::VERSION, 'php' => PHP_VERSION]);

            $client->trace('test.child', function (): void {
                usleep(20_000);
            });

            try {
                throw new RuntimeException('This is a test exception from elgiosoft-logger:test', previous: new RuntimeException('Previous exception'));
            } catch (RuntimeException $exception) {
                $client->captureException($exception, ['event' => 'logger.test_exception']);
            }
        }, 'console.command');

        $traceId = (string) $tracer->traceId();
        $delivered = true;

        foreach ($client->drainEnvelopes() as $envelope) {
            $delivered = $client->withoutRecording(fn (): bool => $transport->post($envelope)) && $delivered;
        }

        $tracer->reset();

        if (! $delivered) {
            $this->components->error('The collector rejected the test batch (see the error log for details).');

            return self::FAILURE;
        }

        $this->components->info('Test events sent.');
        $this->components->twoColumnDetail('Trace id', $traceId);
        $this->line('  Open the dashboard and search: <fg=cyan>trace:'.$traceId.'</>');

        return self::SUCCESS;
    }
}
