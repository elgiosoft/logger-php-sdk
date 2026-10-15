<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

final class NotifyMerchant implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $transactionId, public ?string $traceId = null) {}

    public function handle(): void
    {
        Log::info('Notifying merchant', ['transaction_id' => $this->transactionId]);
    }
}
