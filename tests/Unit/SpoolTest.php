<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Unit;

use Elgiosoft\Logger\Transport\Delivery;
use Elgiosoft\Logger\Transport\Spool;
use PHPUnit\Framework\TestCase;

final class SpoolTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/elgiosoft-spool-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function test_batches_are_replayed_oldest_first_and_removed_once_sent(): void
    {
        $spool = new Spool($this->directory);
        $spool->push(['logs' => [['message' => 'first']]]);
        $spool->push(['logs' => [['message' => 'second']]]);
        $sent = [];

        $this->assertSame(2, $spool->drain(function (array $envelope) use (&$sent): bool {
            $sent[] = $envelope['logs'][0]['message'];

            return true;
        }));

        $this->assertSame(['first', 'second'], $sent);
        $this->assertSame(0, $spool->count());
    }

    public function test_a_failed_replay_puts_the_batch_back_and_stops(): void
    {
        $spool = new Spool($this->directory);
        $spool->push(['logs' => [['message' => 'first']]]);
        $spool->push(['logs' => [['message' => 'second']]]);
        $calls = 0;

        $this->assertSame(0, $spool->drain(function () use (&$calls): bool {
            $calls++;

            return false;
        }));

        $this->assertSame(1, $calls);
        $this->assertSame(2, $spool->count());
    }

    public function test_the_spool_is_bounded(): void
    {
        $spool = new Spool($this->directory, maxFiles: 2);

        $this->assertTrue($spool->push(['logs' => [['message' => 'a']]]));
        $this->assertTrue($spool->push(['logs' => [['message' => 'b']]]));
        $this->assertFalse($spool->push(['logs' => [['message' => 'c']]]));
    }

    public function test_expired_batches_are_dropped(): void
    {
        $spool = new Spool($this->directory, maxAgeSeconds: 60);
        $spool->push(['logs' => [['message' => 'old']]]);
        touch(glob($this->directory.'/*.json')[0], time() - 3600);

        $this->assertSame(0, $spool->drain(fn (): bool => true));
        $this->assertSame(0, $spool->count());
    }

    public function test_envelopes_split_into_two_halves(): void
    {
        $envelope = ['environment' => 'production', 'logs' => [['id' => 1], ['id' => 2], ['id' => 3]], 'spans' => [['id' => 's1'], ['id' => 's2']]];

        [$first, $second] = Delivery::split($envelope);

        $this->assertSame('production', $first['environment']);
        $this->assertSame([['id' => 1], ['id' => 2]], $first['logs']);
        $this->assertSame([['id' => 's1']], $first['spans']);
        $this->assertSame([['id' => 3]], $second['logs']);
        $this->assertSame([['id' => 's2']], $second['spans']);
        $this->assertNull(Delivery::split(['logs' => [['id' => 1]]]));
        $this->assertSame([['spans' => [['id' => 's1']]], ['spans' => [['id' => 's2']]]], Delivery::split(['spans' => [['id' => 's1'], ['id' => 's2']]]));
    }
}
