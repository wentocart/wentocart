<?php

namespace Tests\Unit;

use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionBilling;
use App\Domain\Subscription\Services\SubscriptionService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionBillingFrequencyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_frequencies_include_hour_first(): void
    {
        $frequencies = app(SubscriptionService::class)->frequencies();

        $this->assertSame(['hour', 'day', 'week', 'month', 'year'], $frequencies);
    }

    public function test_billing_and_trial_labels_support_hour(): void
    {
        $svc = app(SubscriptionService::class);

        $this->assertSame('Every 1 Hour', $svc->billingLabel(1, 'hour'));
        $this->assertSame('Every 3 Hours', $svc->billingLabel(3, 'hour'));

        $plan = new SubscriptionPlan([
            'trial_enabled' => true,
            'trial_duration' => 2,
            'trial_cycle' => 1,
            'trial_frequency' => 'hour',
        ]);

        $this->assertSame('2 Hours', $svc->trialSummary($plan));
    }

    #[DataProvider('trialFrequencyProvider')]
    public function test_trial_ends_at_honours_each_frequency(string $frequency, string $method, int $amount): void
    {
        Carbon::setTestNow('2026-03-15 10:00:00');

        $plan = new SubscriptionPlan([
            'trial_enabled' => true,
            'trial_duration' => 2,
            'trial_cycle' => 3,
            'trial_frequency' => $frequency,
        ]);

        $ends = $this->invokeBilling('trialEndsAt', $plan);
        $expected = Carbon::parse('2026-03-15 10:00:00')->{$method}($amount);

        $this->assertTrue($expected->equalTo($ends), "Expected {$expected} for {$frequency}, got {$ends}");
    }

    public static function trialFrequencyProvider(): array
    {
        // duration 2 * cycle 3 = 6 units
        return [
            'hour' => ['hour', 'addHours', 6],
            'day' => ['day', 'addDays', 6],
            'week' => ['week', 'addWeeks', 6],
            'month' => ['month', 'addMonths', 6],
            'year' => ['year', 'addYears', 6],
        ];
    }

    #[DataProvider('billingFrequencyProvider')]
    public function test_next_renewal_honours_each_frequency(string $frequency, string $method, int $cycle): void
    {
        Carbon::setTestNow('2026-03-15 10:00:00');
        $from = Carbon::parse('2026-03-15 10:00:00');

        $plan = new SubscriptionPlan([
            'cycle' => $cycle,
            'frequency' => $frequency,
        ]);

        $next = $this->invokeBilling('nextAt', $plan, $from->copy());
        $expected = $from->copy()->{$method}($cycle);

        $this->assertTrue($expected->equalTo($next), "Expected {$expected} for {$frequency}, got {$next}");
    }

    public static function billingFrequencyProvider(): array
    {
        return [
            'hour' => ['hour', 'addHours', 2],
            'day' => ['day', 'addDays', 2],
            'week' => ['week', 'addWeeks', 2],
            'month' => ['month', 'addMonths', 2],
            'year' => ['year', 'addYears', 2],
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
