<?php

namespace Tests\Feature;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Models\Admin;

class AdminGraphqlWave361Test extends MarketplaceTestCase
{
    public function test_marketplace_landing_page_admin_and_storefront(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        MarketplaceSettings::setEnabled(true);

        $save = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/landing-page', [
            'bannerTitle' => 'Open your shop today',
            'bannerDescription' => 'Join thousands of sellers.',
            'bannerButtonTitle' => 'Start selling',
            'businessHour' => '24x7',
            'paymentDuration' => '3',
            'serviceablePincode' => '5000',
        ]);
        $save->assertOk();
        $this->assertSame('Open your shop today', (string) ($save->json('data.bannerTitle') ?? ''));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/landing-page');
        $show->assertOk();
        $this->assertSame('3', (string) ($show->json('data.paymentDuration') ?? ''));

        $this->flushSession();
        auth('admin')->logout();
        $public = $this->getJson('/api/v1/marketplace/landing');
        $public->assertOk();
        $this->assertSame('Open your shop today', (string) ($public->json('data.bannerTitle') ?? ''));
        $this->assertSame('24x7', (string) ($public->json('data.highlights.businessHour') ?? ''));
        $this->assertCount(5, $public->json('data.journeySteps') ?? []);
    }
}
