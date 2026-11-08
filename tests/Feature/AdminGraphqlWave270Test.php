<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave270Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_attribute_option_show(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $list = $this->getJson('/api/v1/store/attribute-options?per_page=5');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $firstId = (int) ($list->json('0.id') ?? 0);
        if ($firstId < 1) {
            $firstId = (int) (FeatureValue::query()->where('is_enabled', true)->value('id') ?? 0);
        }
        $this->assertGreaterThan(0, $firstId);

        $show = $this->getJson('/api/v1/store/attribute-options/'.$firstId);
        $show->assertOk();
        $this->assertSame($firstId, (int) $show->json('id'));
        $this->assertArrayHasKey('translation', $show->json() ?? []);
        $this->assertNotEmpty((string) ($show->json('translation.label') ?? $show->json('adminName') ?? ''));

        $missing = $this->getJson('/api/v1/store/attribute-options/99999999');
        $missing->assertNotFound();
    }
}
