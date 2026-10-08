<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Facades\ElgioLogger;
use Elgiosoft\Logger\Tests\Fixtures\PaymentService;
use Elgiosoft\Logger\Tests\Fixtures\ProviderTimeout;
use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ExceptionSerializationTest extends TestCase
{
    public function test_exception_chain_is_serialized_innermost_frame_first(): void
    {
        $this->fakeCollector();

        try {
            (new PaymentService)->cashout(25000);
        } catch (ProviderTimeout $exception) {
            Log::channel('elgiosoft')->error($exception->getMessage(), ['exception' => $exception]);
        }

        $this->client()->flush();
        $log = $this->sentLogs()[0];

        $this->assertArrayNotHasKey('exception', $log['context'] ?? []);
        $values = $log['exception']['values'];
        $this->assertCount(2, $values);

        $thrown = $values[0];
        $this->assertSame(ProviderTimeout::class, $thrown['type']);
        $this->assertSame('Maviance cashout timed out after 30s', $thrown['value']);
        $this->assertSame(504, $thrown['code']);

        $innermost = $thrown['frames'][0];
        $fixture = realpath(__DIR__.'/../Fixtures/PaymentService.php');
        $this->assertSame($fixture, $innermost['file']);
        $this->assertSame($thrown['line'], $innermost['line']);
        $this->assertSame('cashout', $innermost['function']);
        $this->assertSame(PaymentService::class, $innermost['class']);
        $this->assertTrue($innermost['in_app']);
        $this->assertStringContainsString('throw new ProviderTimeout', $innermost['context_line']);
        $this->assertCount(5, $innermost['pre_context']);
        $this->assertNotEmpty($innermost['post_context']);

        $previous = $values[1];
        $this->assertSame(RuntimeException::class, $previous['type']);
        $this->assertSame('callProvider', $previous['frames'][0]['function']);
        $this->assertSame('Connection reset while sending 25000 XAF', $previous['value']);

        $vendorFrames = array_filter($thrown['frames'], fn (array $frame): bool => str_contains($frame['file'] ?? '', '/vendor/'));
        $this->assertNotEmpty($vendorFrames);

        foreach ($vendorFrames as $frame) {
            $this->assertFalse($frame['in_app']);
            $this->assertArrayNotHasKey('context_line', $frame);
        }
    }

    public function test_php_warnings_point_at_the_function_that_raised_them(): void
    {
        $serializer = new \Elgiosoft\Logger\Serializers\ExceptionSerializer([dirname(__DIR__)]);
        $readCurrency = static function (array $wallet): mixed {
            return $wallet['currency'];
        };

        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $readCurrency(['balance' => 10]);
            $this->fail('Expected a warning.');
        } catch (\ErrorException $exception) {
            $frames = $serializer->serialize($exception)['values'][0]['frames'];
        } finally {
            restore_error_handler();
        }

        $this->assertSame(__FILE__, $frames[0]['file']);
        $this->assertSame($exception->getLine(), $frames[0]['line']);
        $this->assertStringContainsString('{closure', $frames[0]['function']);
        $this->assertSame(__FUNCTION__, $frames[1]['function']);
    }

    public function test_capture_exception_through_the_facade(): void
    {
        $this->fakeCollector();

        $id = ElgioLogger::captureException(new RuntimeException('Boom'), ['transaction_id' => 'txn_9', 'fingerprint' => ['custom-group']]);
        ElgioLogger::flush();

        $log = $this->sentLogs()[0];
        $this->assertSame($id, $log['id']);
        $this->assertSame('error', $log['level']);
        $this->assertSame('Boom', $log['message']);
        $this->assertSame('txn_9', $log['transaction_id']);
        $this->assertSame(['custom-group'], $log['fingerprint']);
        $this->assertSame(RuntimeException::class, $log['exception']['values'][0]['type']);
    }
}
