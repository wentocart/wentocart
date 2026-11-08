<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave265Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_type_subresources(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $parent = Product::query()->create([
            'type' => 'bundle',
            'sku' => 'W265-BUNDLE-'.uniqid(),
            'price' => 99,
            'stock_qty' => 10,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
        ]);
        $parent->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave265 Bundle',
            'slug' => 'wave265-bundle-'.uniqid(),
        ]);
        $child = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        $comp = ProductComponent::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'quantity' => 1,
            'is_required' => true,
            'default_selected' => true,
            'qty_editable' => false,
            'option_group' => 'Size',
            'position' => 1,
        ]);

        $variants = $this->getJson('/api/v1/store/products/'.$child->id.'/variants');
        $variants->assertOk();
        $this->assertIsArray($variants->json());

        $booking = $this->getJson('/api/v1/store/products/'.$child->id.'/bookings');
        $booking->assertOk();
        $this->assertIsArray($booking->json());

        $bundle = $this->getJson('/api/v1/store/products/'.$parent->id.'/bundle-options');
        $bundle->assertOk();
        $this->assertNotEmpty($bundle->json());
        $this->assertSame('Size', (string) $bundle->json('0.label'));

        $member = $this->getJson('/api/v1/store/product-bundle-option-products/'.$comp->id);
        $member->assertOk();
        $this->assertSame((int) $comp->id, (int) $member->json('id'));
        $this->assertStringContainsString('/api/v1/store/products/'.$child->id, (string) $member->json('product'));

        $grouped = $this->getJson('/api/v1/store/products/'.$parent->id.'/grouped-products');
        $grouped->assertOk();
        $this->assertNotEmpty($grouped->json());
        $this->assertSame((int) $comp->id, (int) $grouped->json('0.id'));

        $showGrouped = $this->getJson('/api/v1/store/product_grouped_products/'.$comp->id);
        $showGrouped->assertOk();

        $links = $this->getJson('/api/v1/store/products/'.$child->id.'/downloadable-links');
        $links->assertOk();
        $this->assertIsArray($links->json());

        $samples = $this->getJson('/api/v1/store/products/'.$child->id.'/downloadable-samples');
        $samples->assertOk();
        $this->assertIsArray($samples->json());
    }
}
