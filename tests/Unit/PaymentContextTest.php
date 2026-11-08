<?php

namespace Tests\Unit;

use App\Domain\Payment\Support\PaymentContext;
use PHPUnit\Framework\TestCase;

class PaymentContextTest extends TestCase
{
    public function test_normalizes_scalar_and_nested_values(): void
    {
        $normalized = PaymentContext::normalize([
            'payment_intent_id' => ' pi_123 ',
            'nested' => ['token' => 'abc'],
            'bad key' => 'x',
            123 => 'ignored',
        ]);

        $this->assertSame([
            'payment_intent_id' => 'pi_123',
            'nested' => ['token' => 'abc'],
        ], $normalized);
    }

    public function test_rejects_overlong_strings(): void
    {
        $normalized = PaymentContext::normalize([
            'payment_intent_id' => str_repeat('a', 300),
        ]);

        $this->assertSame([], $normalized);
    }
}
