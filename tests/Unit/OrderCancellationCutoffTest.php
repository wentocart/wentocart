<?php

namespace Tests\Unit;

use App\Domain\Settings\OrderSettings;
use PHPUnit\Framework\TestCase;

class OrderCancellationCutoffTest extends TestCase
{
    /** @var list<string> */
    private array $statuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];

    public function test_legacy_cutoffs_map_onto_status_codes(): void
    {
        $this->assertSame('processing', OrderSettings::canonicalCancellationUntil('before_processing'));
        $this->assertSame('shipped', OrderSettings::canonicalCancellationUntil('before_shipped'));
        $this->assertSame('completed', OrderSettings::canonicalCancellationUntil(' Completed '));
    }

    public function test_cancellation_stays_open_only_before_the_chosen_status(): void
    {
        $this->assertTrue(OrderSettings::statusComesBeforeCutoff('pending', 'processing', $this->statuses));
        $this->assertFalse(OrderSettings::statusComesBeforeCutoff('processing', 'processing', $this->statuses));

        $this->assertTrue(OrderSettings::statusComesBeforeCutoff('pending', 'shipped', $this->statuses));
        $this->assertTrue(OrderSettings::statusComesBeforeCutoff('processing', 'shipped', $this->statuses));
        $this->assertFalse(OrderSettings::statusComesBeforeCutoff('shipped', 'shipped', $this->statuses));

        $this->assertTrue(OrderSettings::statusComesBeforeCutoff('shipped', 'completed', $this->statuses));
        $this->assertTrue(OrderSettings::statusComesBeforeCutoff('completed', 'cancelled', $this->statuses));
        $this->assertTrue(OrderSettings::statusComesBeforeCutoff('cancelled', 'refunded', $this->statuses));
        $this->assertFalse(OrderSettings::statusComesBeforeCutoff('refunded', 'refunded', $this->statuses));
        $this->assertFalse(OrderSettings::statusComesBeforeCutoff('pending', 'pending', $this->statuses));
        $this->assertFalse(OrderSettings::statusComesBeforeCutoff('custom', 'shipped', $this->statuses));
    }

    public function test_legacy_stock_timing_maps_onto_a_status(): void
    {
        $this->assertSame('pending', OrderSettings::canonicalReduceStockAt('order'));
        $this->assertSame('processing', OrderSettings::canonicalReduceStockAt('payment'));
        $this->assertSame('shipped', OrderSettings::canonicalReduceStockAt(' Shipped '));
    }
}
