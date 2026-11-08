<?php

namespace Tests\Feature;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Models\Admin;

class AdminGraphqlWave333Test extends MarketplaceTestCase
{
    public function test_marketplace_max_upload_mb_setting(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $saved = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/settings', [
            'maxUploadMb' => 5,
        ]);
        $saved->assertOk();
        $this->assertSame(5, (int) $saved->json('data.maxUploadMb'));
        $this->assertSame(5, MarketplaceSettings::maxUploadMb());
        $this->assertSame(5120, MarketplaceSettings::maxUploadKb());

        MarketplaceSettings::save(['max_upload_mb' => 10]);
    }
}
