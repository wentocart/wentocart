<?php

namespace Tests\Feature;

use App\Domain\Marketplace\FlagReasonSchema;
use App\Domain\Marketplace\Models\MarketplaceFlagReason;
use App\Models\Admin;

class AdminGraphqlWave317Test extends MarketplaceTestCase
{
    public function test_rest_marketplace_flag_reasons_rest(): void
    {
        $this->seed();
        FlagReasonSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/flag-reasons', [
            'label' => 'Counterfeit '.$suffix,
            'code' => 'counterfeit-'.$suffix,
            'applies_to' => 'both',
            'is_enabled' => true,
            'position' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('data.id');

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/flag-reasons?applies_to=seller');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));

        $this->flushSession();
        auth('admin')->logout();
        $public = $this->getJson('/api/v1/catalog/flag-reasons');
        $public->assertOk();
        $this->actingAs($admin, 'admin');

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/flag-reasons/'.$id, [
            'label' => 'Fake goods '.$suffix,
            'applies_to' => 'product',
            'is_enabled' => false,
        ]);
        $update->assertOk();
        $this->assertSame(0, (int) ($update->json('data.isEnabled') ?? 1));

        $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketplace/flag-reasons/'.$id)->assertOk();
        $this->assertNull(MarketplaceFlagReason::query()->find($id));
    }
}
