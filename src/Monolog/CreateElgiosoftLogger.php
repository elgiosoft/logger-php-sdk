<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Monolog;

use Elgiosoft\Logger\Client;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Logger;

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

        return new Logger(
            $config['name'] ?? $app->environment(),
            [new ElgiosoftHandler($client, Logger::toMonologLevel($level), (bool) ($config['bubble'] ?? true))],
        );
    }
}
