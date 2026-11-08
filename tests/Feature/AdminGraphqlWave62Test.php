<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Store\Models\Store;
use App\Domain\Theme\ThemeRegistry;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave62Test extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_rates_channels_themes_and_marketing_subscribers_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $usd = Currency::query()->where('code', 'USD')->first()
            ?? Currency::query()->where('is_default', true)->first()
            ?? Currency::query()->first();
        $this->assertNotNull($usd);

        $rates = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminSettingsExchangeRates(limit: 20) {
                    id targetCurrencyCode rate
                }
                adminSettingsExchangeRate(id: "'.$usd->id.'") {
                    id currency_code rate
                }
            }',
        ]);
        $rates->assertOk();
        $this->assertArrayNotHasKey('errors', $rates->json() ?? [], json_encode($rates->json()));
        $this->assertNotEmpty($rates->json('data.adminSettingsExchangeRates'));
        $this->assertSame((string) $usd->id, (string) $rates->json('data.adminSettingsExchangeRate.id'));

        $updated = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsExchangeRate(id: $id, rate: 1.25) {
                    id rate exchange_rate
                }
            }',
            'variables' => ['id' => (string) $usd->id],
        ]);
        $updated->assertOk();
        $this->assertArrayNotHasKey('errors', $updated->json() ?? [], json_encode($updated->json()));
        $this->assertSame(1.25, (float) $updated->json('data.updateAdminSettingsExchangeRate.rate'));
        $this->assertSame(1.25, (float) Currency::query()->find($usd->id)?->exchange_rate);

        $sync = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminSettingsExchangeRateUpdateRates {
                    ok success updated message
                }
            }',
        ]);
        $sync->assertOk();
        $this->assertArrayNotHasKey('errors', $sync->json() ?? [], json_encode($sync->json()));
        $this->assertTrue((bool) $sync->json('data.createAdminSettingsExchangeRateUpdateRates.ok'));
        $this->assertGreaterThan(0, (int) $sync->json('data.createAdminSettingsExchangeRateUpdateRates.updated'));

        $channels = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminSettingsStores(limit: 10) { id code name theme hostname status }
                adminSettingsStore(code: "default") { id code theme is_default }
            }',
        ]);
        $stores->assertOk();
        $this->assertArrayNotHasKey('errors', $stores->json() ?? [], json_encode($stores->json()));
        $this->assertNotEmpty($stores->json('data.adminSettingsStores'));
        $storeId = (string) ($stores->json('data.adminSettingsStore.id')
            ?: $stores->json('data.adminSettingsStores.0.id'));
        $this->assertNotSame('', $storeId);

        $themes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminAppearanceThemes { code displayName active status channels { id code } }
                adminAppearanceTheme(code: "classic") { code active }
                adminAppearanceThemeImpact(code: "classic", storeIds: ["'.$storeId.'"]) {
                    code would_switch { id } already_active { id } message
                }
            }',
        ]);
        $themes->assertOk();
        $this->assertArrayNotHasKey('errors', $themes->json() ?? [], json_encode($themes->json()));
        $this->assertNotEmpty($themes->json('data.adminAppearanceThemes'));
        $this->assertSame('classic', (string) $themes->json('data.adminAppearanceTheme.code'));

        $activate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]) {
                createAdminAppearanceThemeActivate(code: "classic", storeIds: $ids) {
                    code activatedOn { id code } message
                }
            }',
            'variables' => ['ids' => [$storeId]],
        ]);
        $activate->assertOk();
        $this->assertArrayNotHasKey('errors', $activate->json() ?? [], json_encode($activate->json()));
        $this->assertSame('classic', (string) $activate->json('data.createAdminAppearanceThemeActivate.code'));
        $this->assertSame(
            ThemeRegistry::CLASSIC,
            app(ThemeRegistry::class)->canonical(Store::query()->find((int) $storeId)?->theme)
        );

        $email = 'wave62-'.substr(uniqid(), -6).'@example.test';
        $subscriber = NewsletterSubscriber::query()->create([
            'email' => $email,
            'is_subscribed' => true,
        ]);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($q: String!) {
                adminMarketingSubscribers(q: $q, limit: 10) { id email isSubscribed }
                adminMarketingSubscriber(id: "'.$subscriber->id.'") { id email is_subscribed }
            }',
            'variables' => ['q' => 'wave62-'],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminMarketingSubscribers'));
        $this->assertTrue((bool) $list->json('data.adminMarketingSubscriber.is_subscribed'));

        $toggle = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminMarketingSubscriber(id: $id, isSubscribed: false) {
                    id isSubscribed is_subscribed
                }
            }',
            'variables' => ['id' => (string) $subscriber->id],
        ]);
        $toggle->assertOk();
        $this->assertArrayNotHasKey('errors', $toggle->json() ?? [], json_encode($toggle->json()));
        $this->assertFalse((bool) $toggle->json('data.updateAdminMarketingSubscriber.isSubscribed'));
        $this->assertFalse((bool) NewsletterSubscriber::query()->find($subscriber->id)?->is_subscribed);

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminMarketingSubscriber(id: $id) { ok id }
            }',
            'variables' => ['id' => (string) $subscriber->id],
        ]);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('data.deleteAdminMarketingSubscriber.ok'));
        $this->assertNull(NewsletterSubscriber::query()->find($subscriber->id));
    }
}
