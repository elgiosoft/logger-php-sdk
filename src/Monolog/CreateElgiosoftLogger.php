<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Monolog;

use Elgiosoft\Logger\Client;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Factory for the "elgiosoft" log driver:
 *
 *     'elgiosoft' => ['driver' => 'elgiosoft', 'level' => env('LOG_LEVEL', 'debug')],
 */
final class CreateElgiosoftLogger
{
    /**
     * @param  array{name?: string, level?: string, bubble?: bool}  $config
     */
    public function __invoke(Application $app, array $config): Logger
    {
        $client = $app->make(Client::class);
        $level = $config['level'] ?? ($client->config()['level'] ?? 'debug');

        $bubble = (bool) ($config['bubble'] ?? true);

        // Monolog 3 (Laravel 10+) passes LogRecord objects; Monolog 2 (Laravel 9) passes arrays.
        $handler = class_exists(LogRecord::class)
            ? new ElgiosoftHandler($client, Logger::toMonologLevel($level), $bubble)
            : new LegacyElgiosoftHandler($client, Logger::toMonologLevel($level), $bubble);

        return new Logger($config['name'] ?? $app->environment(), [$handler]);
    }
}
