<?php

namespace Tests\Unit;

use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionBilling;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionRenewalScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_next_at_advances_exactly_one_interval_from_due_not_now(): void
    {
        Carbon::setTestNow('2026-10-07 08:05:00'); // scheduler ran late
        $due = Carbon::parse('2026-10-07 08:04:00');
        $plan = new SubscriptionPlan(['cycle' => 1, 'frequency' => 'hour']);

        $next = $this->invokeBilling('nextAt', $plan, $due->copy());

        $this->assertTrue(
            Carbon::parse('2026-10-07 09:04:00')->equalTo($next),
            "Expected 09:04:00 from due 08:04, got {$next}",
        );
        $this->assertFalse(Carbon::parse('2026-10-07 09:05:00')->equalTo($next));
    }

    public function test_delayed_scheduler_does_not_accumulate_drift_across_cycles(): void
    {
        $plan = new SubscriptionPlan(['cycle' => 1, 'frequency' => 'hour']);
        $anchor = Carbon::parse('2026-10-07 07:04:50');

        // Simulate three renewals processed 10s, then 70s, then 5s late.
        $due = $anchor->copy()->addHour(); // 08:04:50
        Carbon::setTestNow($due->copy()->addSeconds(10));
        $due = $this->invokeBilling('nextAt', $plan, $due);
        $this->assertSame('2026-10-07 09:04:50', $due->format('Y-m-d H:i:s'));

        Carbon::setTestNow($due->copy()->addSeconds(70));
        $due = $this->invokeBilling('nextAt', $plan, $due);
        $this->assertSame('2026-10-07 10:04:50', $due->format('Y-m-d H:i:s'));

        Carbon::setTestNow($due->copy()->addSeconds(5));
        $due = $this->invokeBilling('nextAt', $plan, $due);
        $this->assertSame('2026-10-07 11:04:50', $due->format('Y-m-d H:i:s'));
    }

    #[DataProvider('intervalProvider')]
    public function test_interval_math_from_previous_due(string $frequency, string $method, int $cycle): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $from = Carbon::parse('2026-06-01 10:00:00');
        $plan = new SubscriptionPlan(['cycle' => $cycle, 'frequency' => $frequency]);

        $next = $this->invokeBilling('nextAt', $plan, $from->copy());
        $expected = $from->copy()->{$method}($cycle);

        $this->assertTrue($expected->equalTo($next), "Expected {$expected}, got {$next}");
    }

    public static function intervalProvider(): array
    {
        return [
            'hour' => ['hour', 'addHours', 1],
            'day' => ['day', 'addDays', 1],
            'week' => ['week', 'addWeeks', 1],
            'month' => ['month', 'addMonths', 1],
            'year' => ['year', 'addYears', 1],
        ];
    }

    /** @param  mixed  ...$args */
    protected function invokeBilling(string $method, mixed ...$args): mixed
    {
        $billing = app(SubscriptionBilling::class);
        $ref = new \ReflectionMethod(SubscriptionBilling::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($billing, ...$args);
    }
}
