<?php

namespace Tests\Feature;

use App\Domain\Marketplace\MarketplaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marketplace feature tests run with Multi-Vendor enabled.
 * Feature-flag coverage lives in MarketplaceFeatureFlagTest (extends TestCase).
 */
abstract class MarketplaceTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed defaults then turn Multi-Vendor on so existing Marketplace suites stay valid.
     *
     * @param  string|array<int, class-string>|null  $class
     * @return $this
     */
    public function seed($class = 'Database\\Seeders\\DatabaseSeeder')
    {
        parent::seed($class);
        MarketplaceSettings::setEnabled(true);

        return $this;
    }
}
