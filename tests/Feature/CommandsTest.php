<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Feature;

use Elgiosoft\Logger\Tests\TestCase;
use Illuminate\Support\Facades\Http;

final class CommandsTest extends TestCase
{
    public function test_test_command_sends_a_log_an_exception_and_a_trace(): void
    {
        Http::fake([
            self::ENDPOINT.'/api/v1/ping*' => Http::response(['ok' => true, 'project' => 'yankap']),
            self::ENDPOINT.'/api/v1/ingest' => Http::response(['accepted' => ['logs' => 2, 'spans' => 2], 'rejected' => 0], 202),
        ]);

        $this->artisan('elgiosoft-logger:test')
            ->expectsOutputToContain('Key accepted for project "yankap"')
            ->expectsOutputToContain('Test events sent.')
            ->assertSuccessful();

        $logs = collect($this->sentLogs());
        $spans = collect($this->sentSpans());

        $this->assertSame('logger.test', $logs->firstWhere('level', 'info')['event']);
        $exception = $logs->firstWhere('level', 'error');
        $this->assertSame('logger.test_exception', $exception['event']);
        $this->assertCount(2, $exception['exception']['values']);

        $root = $spans->firstWhere('name', 'elgiosoft-logger:test');
        $this->assertSame('error', $root['status']);
        $this->assertSame($root['span_id'], $spans->firstWhere('name', 'test.child')['parent_span_id']);
        $this->assertSame($root['trace_id'], $exception['trace_id']);
    }

    public function test_test_command_fails_with_a_rejected_key(): void
    {
        Http::fake([self::ENDPOINT.'/*' => Http::response(['message' => 'Invalid API key.'], 401)]);

        $this->artisan('elgiosoft-logger:test')
            ->expectsOutputToContain('Ping failed with HTTP 401')
            ->assertFailed();
    }
}
