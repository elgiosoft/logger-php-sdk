<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Fixtures;

use RuntimeException;

final class PaymentService
{
    public function cashout(int $amount): void
    {
        try {
            $this->callProvider($amount);
        } catch (RuntimeException $exception) {
            throw new ProviderTimeout('Maviance cashout timed out after 30s', 504, $exception);
        }
    }

    private function callProvider(int $amount): void
    {
        throw new RuntimeException("Connection reset while sending {$amount} XAF");
    }
}
