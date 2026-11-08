<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave256Test extends TestCase
{
    use RefreshDatabase;

    public function test_gdpr_status_configs_and_currencies_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('gdpr.enabled', true);

        $gdpr = $this->getJson('/api/v1/store/gdpr-status');
        $gdpr->assertOk();
        $this->assertTrue((bool) $gdpr->json('enabled'));
        $this->assertTrue(GdprSettings::enabled());

        $configs = $this->getJson('/api/v1/store/configs');
        $configs->assertOk();
        $this->assertNotEmpty($configs->json('currency') ?? $configs->json('locale') ?? $configs->json());

        $alias = $this->getJson('/api/v1/store/store-config');
        $alias->assertOk();

        $list = $this->getJson('/api/v1/store/currencies');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $this->assertNotEmpty($list->json());
        $code = (string) ($list->json('0.code') ?? '');
        $this->assertNotSame('', $code);

        $show = $this->getJson('/api/v1/store/currencies/'.$code);
        $show->assertOk();
        $this->assertSame($code, (string) $show->json('code'));

        $byId = Currency::query()->where('code', $code)->first();
        $this->assertNotNull($byId);
        $showId = $this->getJson('/api/v1/store/currencies/'.$byId->id);
        $showId->assertOk();
        $this->assertSame($code, (string) $showId->json('code'));
    }
}
