<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Facades\ElgioLogger;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;

final class ScrubbingTest extends TestCase
{
    protected function withPii($app): void
    {
        $app['config']->set('elgiosoft-logger.send_default_pii', true);
    }

    protected function withoutScrubbing($app): void
    {
        $app['config']->set('elgiosoft-logger.scrub_messages', 'false');
    }

    public function test_secrets_in_messages_are_masked(): void
    {
        $this->fakeCollector();

        Log::warning('Charge of 4111 1111 1111 1111 for jane@example.com failed: password=hunter2, header "Authorization: Bearer eyJhbGciOi.payload.sig", order 1729000000000');
        $this->client()->flush();

        $this->assertSame(
            'Charge of [Filtered] for [Filtered] failed: password=[Filtered], header "Authorization: [Filtered]", order 1729000000000',
            $this->sentLogs()[0]['message'],
        );
    }

    public function test_numbers_that_fail_the_luhn_check_are_kept(): void
    {
        $this->fakeCollector();

        Log::info('Reference 4111111111111112 and phone 237670000000');
        $this->client()->flush();

        $this->assertSame('Reference 4111111111111112 and phone 237670000000', $this->sentLogs()[0]['message']);
    }

    public function test_exception_values_are_masked(): void
    {
        $this->fakeCollector();

        ElgioLogger::captureException(new RuntimeException('Login failed for jane@example.com', previous: new RuntimeException('token=abc123 rejected')));
        $this->client()->flush();

        $log = $this->sentLogs()[0];
        $this->assertSame('Login failed for [Filtered]', $log['message']);
        $this->assertSame('Login failed for [Filtered]', $log['exception']['values'][0]['value']);
        $this->assertSame('token=[Filtered] rejected', $log['exception']['values'][1]['value']);
    }

    #[DefineEnvironment('withPii')]
    public function test_emails_are_kept_when_pii_may_be_sent(): void
    {
        $this->fakeCollector();

        Log::info('Welcome mail sent to jane@example.com, pin: 1234');
        $this->client()->flush();

        $this->assertSame('Welcome mail sent to jane@example.com, pin: [Filtered]', $this->sentLogs()[0]['message']);
    }

    #[DefineEnvironment('withoutScrubbing')]
    public function test_scrubbing_can_be_turned_off(): void
    {
        $this->fakeCollector();

        Log::info('password=hunter2 for jane@example.com');
        $this->client()->flush();

        $this->assertSame('password=hunter2 for jane@example.com', $this->sentLogs()[0]['message']);
    }
}
