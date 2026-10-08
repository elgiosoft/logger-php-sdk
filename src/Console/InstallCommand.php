<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'elgiosoft-logger:install {--force : Overwrite an existing config file}';

    protected $description = 'Publish the Elgiosoft Logger config and print the setup steps';

    public function handle(): int
    {
        $this->call('vendor:publish', array_filter([
            '--tag' => 'elgiosoft-logger-config',
            '--force' => (bool) $this->option('force'),
        ]));

        $this->newLine();
        $this->components->info('1. Add to your .env');
        $this->line(<<<'ENV'
    ELGIOSOFT_LOGGER_ENDPOINT=https://logger.elgiosoft.com
    ELGIOSOFT_LOGGER_KEY=elg_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
    ELGIOSOFT_LOGGER_TRANSPORT=queue
ENV);

        $this->newLine();
        $this->components->info('2. Add the channel to config/logging.php and put it in your stack');
        $this->line(<<<'PHP'
    'stack' => [
        'driver' => 'stack',
        'channels' => explode(',', env('LOG_STACK', 'single,elgiosoft')),
        'ignore_exceptions' => false,
    ],

    'elgiosoft' => [
        'driver' => 'elgiosoft',
        'level' => env('ELGIOSOFT_LOGGER_LEVEL', 'debug'),
    ],
PHP);

        $this->newLine();
        $this->components->info('3. Make sure a queue worker runs (transport=queue), then verify');
        $this->line('    php artisan elgiosoft-logger:test');

        return self::SUCCESS;
    }
}
