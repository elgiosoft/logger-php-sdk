<?php

declare(strict_types=1);

namespace Elgiosoft\Logger\Tests\Unit;

use Elgiosoft\Logger\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function test_luhn_check(): void
    {
        $this->assertTrue(Redactor::passesLuhn('4111111111111111'));
        $this->assertTrue(Redactor::passesLuhn('378282246310005'));
        $this->assertFalse(Redactor::passesLuhn('4111111111111112'));
        $this->assertFalse(Redactor::passesLuhn('123456789012'));
    }

    public function test_card_numbers_with_separators_are_masked(): void
    {
        $redactor = new Redactor([]);

        $this->assertSame('card [Filtered] ok', $redactor->scrubText('card 5555-5555-5555-4444 ok'));
        $this->assertSame('amex [Filtered]', $redactor->scrubText('amex 378282246310005'));
        $this->assertSame('ts 1729000000000123', $redactor->scrubText('ts 1729000000000123'));
        $this->assertSame('v1.4111111111111111.2', $redactor->scrubText('v1.4111111111111111.2'));
    }

    public function test_key_value_pairs_of_sensitive_keys_are_masked(): void
    {
        $redactor = new Redactor(['password', 'api_key', 'pin']);

        $this->assertSame('password=[Filtered]&user=jo', $redactor->scrubText('password=s3cret&user=jo'));
        $this->assertSame('{"apiKey": "[Filtered]", "id": 1}', $redactor->scrubText('{"apiKey": "my key", "id": 1}'));
        $this->assertSame("api-key: '[Filtered]'", $redactor->scrubText("api-key: 'abc def'"));
        $this->assertSame('<pin>[Filtered]</pin><id>3</id>', $redactor->scrubText('<pin>1234</pin><id>3</id>'));
        $this->assertSame('where pin = ? and pin = :pin and pin = $1', $redactor->scrubText('where pin = ? and pin = :pin and pin = $1'));
    }

    public function test_credentials_are_masked(): void
    {
        $redactor = new Redactor([]);

        $this->assertSame('Bearer [Filtered]', $redactor->scrubText('Bearer eyJhbGciOiJIUzI1NiJ9.e30.abc'));
        $this->assertSame('basic [Filtered] sent', $redactor->scrubText('basic dXNlcjpwYXNz sent'));
    }

    public function test_emails_are_masked_only_when_asked(): void
    {
        $this->assertSame('mail [Filtered]', (new Redactor([], true))->scrubText('mail jane.doe+x@mail.example.cm'));
        $this->assertSame('mail jane@example.com', (new Redactor([]))->scrubText('mail jane@example.com'));
    }
}
