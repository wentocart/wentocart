<?php

namespace Tests\Feature;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Models\Admin;

class AdminGraphqlWave341Test extends MarketplaceTestCase
{
    public function test_marketplace_magic_ai_feature_settings(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $saved = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/settings', [
            'aiAllowReferenceImages' => false,
            'aiMaxReferenceImages' => 5,
            'aiLetSellersChooseFields' => false,
        ]);
        $saved->assertOk();
        $this->assertFalse((bool) $saved->json('data.aiAllowReferenceImages'));
        $this->assertSame(5, (int) $saved->json('data.aiMaxReferenceImages'));
        $this->assertFalse((bool) $saved->json('data.aiLetSellersChooseFields'));
        $this->assertFalse(MarketplaceSettings::aiAllowReferenceImages());
        $this->assertSame(5, MarketplaceSettings::aiMaxReferenceImages());

        MarketplaceSettings::save([
            'ai_allow_reference_images' => true,
            'ai_max_reference_images' => 3,
            'ai_let_sellers_choose_fields' => true,
        ]);
    }
}
