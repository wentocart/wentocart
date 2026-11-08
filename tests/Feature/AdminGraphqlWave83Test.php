<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave83Test extends TestCase
{
    use RefreshDatabase;

    public function test_locales_currencies_csv_and_exchange_rate_mass_delete(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $localesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/locales/export');
        $localesCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $localesCsv->headers->get('content-type'));
        $this->assertNotSame('', trim($localesCsv->streamedContent()));

        $currenciesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/currencies/export');
        $currenciesCsv->assertOk();
        $body = $currenciesCsv->streamedContent();
        $this->assertStringContainsString('code', strtolower($body));

        $target = Currency::query()->where('is_default', false)->orderBy('id')->first();
        if (! $target) {
            $target = Currency::query()->create([
                'code' => 'W83',
                'name' => 'Wave83 Coin',
                'symbol' => 'W',
                'exchange_rate' => 2.5,
                'is_enabled' => true,
                'is_default' => false,
                'position' => 99,
            ]);
        } else {
            $target->exchange_rate = 2.5;
            $target->save();
        }

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminSettingsExchangeRate(ids: [$id]) {
                    ok updated_count deleted skipped message
                }
            }',
            'variables' => ['id' => (string) $target->id],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $this->assertTrue((bool) $mass->json('data.bulkDeleteAdminSettingsExchangeRate.ok'));
        $this->assertGreaterThanOrEqual(1, (int) $mass->json('data.bulkDeleteAdminSettingsExchangeRate.updated_count'));
        $this->assertEqualsWithDelta(1.0, (float) $target->fresh()->exchange_rate, 0.0001);

        $currencyMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminSettingsCurrency(ids: [$id]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => ['id' => (string) $target->id],
        ]);
        $currencyMass->assertOk();
        $this->assertArrayNotHasKey('errors', $currencyMass->json() ?? [], json_encode($currencyMass->json()));
        $this->assertContains((string) $target->id, $currencyMass->json('data.bulkDeleteAdminSettingsCurrency.deleted') ?? []);
        $this->assertNull(Currency::query()->find($target->id));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/locales/export')->assertUnauthorized();
        $this->get('/api/v1/console/currencies/export')->assertUnauthorized();
    }
}
