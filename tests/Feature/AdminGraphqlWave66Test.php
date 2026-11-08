<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave66Test extends TestCase
{
    use RefreshDatabase;

    public function test_settings_locales_and_currencies_rest_crud(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $locale = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminSettingsLocale(code: "fr", name: "Wave66 French", direction: "ltr", status: 1) {
                    id code name status direction
                }
            }',
        ]);
        $locale->assertOk();
        $this->assertArrayNotHasKey('errors', $locale->json() ?? [], json_encode($locale->json()));
        $localeId = (string) $locale->json('data.createAdminSettingsLocale.id');
        $this->assertSame('fr', (string) $locale->json('data.createAdminSettingsLocale.code'));

        $localeUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsLocale(id: $id, name: "Wave66 Français") { id name }
            }',
            'variables' => ['id' => $localeId],
        ]);
        $localeUpdate->assertOk();
        $this->assertSame('Wave66 Français', (string) $localeUpdate->json('data.updateAdminSettingsLocale.name'));

        $currency = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminSettingsCurrency(
                    code: "NOK"
                    name: "Wave66 Krone"
                    symbol: "kr"
                    decimal: 2
                    currencyPosition: "right"
                    exchange_rate: 10.5
                    status: 1
                ) {
                    id code name symbol decimal currencyPosition exchange_rate status
                }
            }',
        ]);
        $currency->assertOk();
        $this->assertArrayNotHasKey('errors', $currency->json() ?? [], json_encode($currency->json()));
        $currencyId = (string) $currency->json('data.createAdminSettingsCurrency.id');
        $this->assertSame('NOK', (string) $currency->json('data.createAdminSettingsCurrency.code'));
        $this->assertSame('right', (string) $currency->json('data.createAdminSettingsCurrency.currencyPosition'));
        $this->assertSame(10.5, (float) $currency->json('data.createAdminSettingsCurrency.exchange_rate'));

        $currencyUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsCurrency(id: $id, name: "Wave66 Norwegian Krone", decimal: 0) {
                    id name decimal
                }
            }',
            'variables' => ['id' => $currencyId],
        ]);
        $currencyUpdate->assertOk();
        $this->assertSame('Wave66 Norwegian Krone', (string) $currencyUpdate->json('data.updateAdminSettingsCurrency.name'));
        $this->assertSame(0, (int) $currencyUpdate->json('data.updateAdminSettingsCurrency.decimal'));

        $lists = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminSettingsLocales(limit: 50) { id code }
                adminSettingsCurrencies(limit: 50) { id code }
                adminSettingsLocale(id: "'.$localeId.'") { id name }
                adminSettingsCurrency(id: "'.$currencyId.'") { id symbol }
            }',
        ]);
        $lists->assertOk();
        $this->assertArrayNotHasKey('errors', $lists->json() ?? [], json_encode($lists->json()));
        $this->assertSame($localeId, (string) $lists->json('data.adminSettingsLocale.id'));
        $this->assertSame($currencyId, (string) $lists->json('data.adminSettingsCurrency.id'));

        $delCurrency = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminSettingsCurrency(id: $id) { ok id } }',
            'variables' => ['id' => $currencyId],
        ]);
        $delCurrency->assertOk();
        $this->assertTrue((bool) $delCurrency->json('data.deleteAdminSettingsCurrency.ok'));
        $this->assertNull(Currency::query()->find((int) $currencyId));

        $delLocale = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminSettingsLocale(id: $id) { ok id } }',
            'variables' => ['id' => $localeId],
        ]);
        $delLocale->assertOk();
        $this->assertTrue((bool) $delLocale->json('data.deleteAdminSettingsLocale.ok'));
        $this->assertNull(Language::query()->find((int) $localeId));
    }
}
