<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Fixtures;

use Elgiosoft\Logger\Facades\ElgioLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;

final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        ElgioLogger::setContext('webhook', ['delivery_id' => $this->deliveryId]);

        throw new RuntimeException('Webhook returned 502');
    }
}
