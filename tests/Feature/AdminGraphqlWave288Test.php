<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave288Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_attribute_options_and_inventories_list(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        $attr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/attributes', [
            'code' => 'w288_'.$suffix,
            'name' => 'Wave288 Attr '.$suffix,
            'type' => 'select',
            'status' => 1,
        ]);
        $attr->assertCreated();
        $attrId = (int) $attr->json('data.id');

        $opt = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/attributes/'.$attrId.'/options', [
            'code' => 'opt_'.$suffix,
            'label' => 'Option '.$suffix,
            'status' => 1,
        ]);
        $opt->assertCreated();
        $optionId = (int) $opt->json('data.id');
        $this->assertGreaterThan(0, $optionId);

        $upd = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/attributes/'.$attrId.'/options/'.$optionId,
            ['label' => 'Option Renamed '.$suffix]
        );
        $upd->assertOk();

        $inv = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$product->id.'/inventories');
        $inv->assertOk();
        $this->assertIsArray($inv->json('data'));
        $this->assertArrayHasKey('totalQty', $inv->json('meta') ?? []);

        $warehouse = Warehouse::query()->where('is_enabled', true)->orderBy('id')->first();
        if ($warehouse) {
            $this->actingAs($admin, 'admin')->postJson(
                '/api/v1/console/products/'.$product->id.'/inventories',
                ['inventories' => [(string) $warehouse->id => 9]]
            )->assertOk();
            $again = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$product->id.'/inventories');
            $again->assertOk();
            $this->assertGreaterThanOrEqual(9, (float) ($again->json('meta.totalQty') ?? 0));
        }

        $this->actingAs($admin, 'admin')
            ->deleteJson('/api/v1/console/attributes/'.$attrId.'/options/'.$optionId)
            ->assertOk();
        $this->assertNull(FeatureValue::query()->find($optionId));
        $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/attributes/'.$attrId)->assertOk();
        $this->assertNull(Feature::query()->find($attrId));
    }
}
