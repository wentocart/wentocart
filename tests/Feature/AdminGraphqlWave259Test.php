<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave259Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_x_locale_currency_channel_headers(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $locale = Language::query()->where('is_enabled', true)->value('code') ?: 'en';
        $currency = Currency::query()->where('is_enabled', true)->value('code') ?: 'USD';
        $store = Store::query()->where('is_enabled', true)->firstOrFail();

        $res = $this->withHeaders([
            'X-Wento-Locale' => $locale,
            'X-Wento-Currency' => $currency,
            'X-Store' => (string) ($store->code ?: $store->id),
        ])->getJson('/api/v1/store/configs');
        $res->assertOk();
        $this->assertSame($locale, app()->getLocale());
        $this->assertSame(strtoupper($currency), strtoupper((string) app('wento.currency')));
        $this->assertSame((int) $store->id, (int) app(StoreContext::class)->id());

        // CMS pages honour X-Wento-Locale for translation pick
        $cms = $this->withHeader('X-Wento-Locale', $locale)->getJson('/api/v1/store/cms-pages');
        $cms->assertOk();
    }
}
