<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Localization\Models\Currency;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave90Test extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_rate_crud_aliases_and_csv_exports(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $ratesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/exchange-rates/export');
        $ratesCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $ratesCsv->headers->get('content-type'));

        $attrsCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/attributes/export');
        $attrsCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $attrsCsv->headers->get('content-type'));

        $target = Currency::query()->where('is_default', false)->orderBy('id')->first()
            ?? Currency::query()->create([
                'code' => 'W90',
                'name' => 'Wave90 Coin',
                'symbol' => 'W',
                'exchange_rate' => 1.0,
                'is_enabled' => true,
                'is_default' => false,
                'position' => 990,
            ]);
        $target->exchange_rate = 1.0;
        $target->save();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminSettingsExchangeRate(targetCurrency: $cid, rate: 2.5) {
                    id rate currency_code
                }
            }',
            'variables' => ['cid' => (string) $target->id],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertEqualsWithDelta(2.5, (float) $create->json('data.createAdminSettingsExchangeRate.rate'), 0.001);

        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminSettingsExchangeRate(targetCurrency: $cid, rate: 3.0) { id }
            }',
            'variables' => ['cid' => (string) $target->id],
        ]);
        $dup->assertOk();
        $this->assertNotEmpty($dup->json('errors'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminSettingsExchangeRate(id: $id) { ok id }
            }',
            'variables' => ['id' => (string) $target->id],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? [], json_encode($del->json()));
        $this->assertTrue((bool) $del->json('data.deleteAdminSettingsExchangeRate.ok'));
        $this->assertEqualsWithDelta(1.0, (float) $target->fresh()->exchange_rate, 0.001);

        $family = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminCatalogProductTemplate(code: "w90-family", name: "Wave90 Family") {
                    id code name
                }
            }',
        ]);
        $family->assertOk();
        $this->assertArrayNotHasKey('errors', $family->json() ?? [], json_encode($family->json()));
        $familyId = (string) $family->json('data.createAdminCatalogProductTemplate.id');
        $this->assertNotNull(ProductTemplate::query()->find((int) $familyId));

        $familyDel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminCatalogProductTemplate(id: $id) { ok id }
            }',
            'variables' => ['id' => $familyId],
        ]);
        $familyDel->assertOk();
        $this->assertArrayNotHasKey('errors', $familyDel->json() ?? [], json_encode($familyDel->json()));
        $this->assertNull(ProductTemplate::query()->find((int) $familyId));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/exchange-rates/export')->assertUnauthorized();
        $this->get('/api/v1/console/attributes/export')->assertUnauthorized();
    }
}
