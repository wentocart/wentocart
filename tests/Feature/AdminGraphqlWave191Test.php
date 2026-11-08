<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave191Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_exchange_rates_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $target = Currency::query()->where('is_default', false)->first()
            ?? Currency::query()->orderByDesc('id')->firstOrFail();
        $target->exchange_rate = 1.0;
        $target->save();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/exchange-rates', [
            'target_currency' => (int) $target->id,
            'rate' => 1.25,
        ]);
        $create->assertCreated();
        $this->assertSame((int) $target->id, (int) $create->json('target_currency'));
        $this->assertEqualsWithDelta(1.25, (float) $create->json('rate'), 0.0001);
        $this->assertSame((string) $target->code, (string) $create->json('targetCurrencyCode'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/exchange-rates?target_currency='.$target->id);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/exchange-rates/'.$target->id);
        $show->assertOk();
        $this->assertEqualsWithDelta(1.25, (float) $show->json('rate'), 0.0001);

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/exchange-rates/'.$target->id, [
            'rate' => 1.5,
        ]);
        $update->assertOk();
        $this->assertEqualsWithDelta(1.5, (float) $update->json('rate'), 0.0001);

        $sync = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/exchange-rates/update-rates');
        $sync->assertOk();
        $this->assertTrue((bool) $sync->json('ok'));
        $this->assertGreaterThan(0, (int) $sync->json('updated'));

        // Re-set a non-identity rate for bulk-delete / delete
        $target->refresh();
        $target->exchange_rate = 2.0;
        $target->save();

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/exchange-rates/bulk-delete', [
            'ids' => [(int) $target->id, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains((int) $target->id, $mass->json('deleted'));
        $this->assertEqualsWithDelta(1.0, (float) $target->fresh()->exchange_rate, 0.0001);

        $target->exchange_rate = 3.0;
        $target->save();
        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/exchange-rates/'.$target->id);
        $del->assertOk();
        $this->assertEqualsWithDelta(1.0, (float) $target->fresh()->exchange_rate, 0.0001);

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsExchangeRates(limit: 5) { id rate targetCurrencyCode } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
