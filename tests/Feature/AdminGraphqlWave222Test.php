<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave222Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_attributes_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $list = $this->getJson('/api/v1/store/attributes?per_page=20');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $this->assertNotEmpty((string) $list->headers->get('X-Total-Count'));

        $feature = Feature::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($feature);

        $show = $this->getJson('/api/v1/store/attributes/'.$feature->id);
        $show->assertOk();
        $this->assertSame((int) $feature->id, (int) $show->json('id'));
        $this->assertSame((string) $feature->code, (string) $show->json('code'));
        $this->assertArrayHasKey('options', $show->json() ?? []);
        $this->assertArrayHasKey('translation', $show->json() ?? []);
        $this->assertContains((int) $show->json('isFilterable'), [0, 1]);

        $opts = $this->getJson('/api/v1/store/attribute-options?attribute_id='.$feature->id.'&per_page=50');
        $opts->assertOk();
        $this->assertIsArray($opts->json());

        $this->getJson('/api/v1/store/attributes/999999')->assertNotFound();
    }
}
