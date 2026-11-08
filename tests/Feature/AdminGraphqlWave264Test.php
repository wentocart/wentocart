<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave264Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_category_translations_and_product_subresources(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $cat = Category::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($cat);

        $cats = $this->getJson('/api/v1/store/categories?per_page=5');
        $cats->assertOk();
        $iri = (string) ($cats->json('0.translations.0') ?? '');
        $this->assertStringContainsString('/api/v1/store/category_translations/', $iri);

        $list = $this->getJson('/api/v1/store/category_translations?per_page=10');
        $list->assertOk();
        $this->assertNotEmpty($list->json());
        $this->assertNotEmpty((string) $list->headers->get('X-Total-Count'));

        $show = $this->getJson('/api/v1/store/category_translations/'.$cat->id);
        $show->assertOk();
        $this->assertSame((int) $cat->id, (int) $show->json('categoryId'));
        $this->assertNotEmpty((string) $show->json('locale'));

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        ProductImage::query()->firstOrCreate(
            ['product_id' => $product->id, 'path' => 'catalog/demo/w264.png'],
            ['alt' => 'Wave264', 'position' => 0],
        );

        $images = $this->getJson('/api/v1/store/products/'.$product->id.'/images');
        $images->assertOk();
        $this->assertIsArray($images->json());
        $this->assertNotEmpty($images->json());
        $this->assertSame('images', (string) $images->json('0.type'));
        $this->assertSame((int) $product->id, (int) $images->json('0.productId'));

        $rootImages = $this->getJson('/api/v1/store/product-images?per_page=5');
        $rootImages->assertOk();

        $tiers = $this->getJson('/api/v1/store/products/'.$product->id.'/customer-group-prices');
        $tiers->assertOk();
        $this->assertIsArray($tiers->json());

        $attrs = $this->getJson('/api/v1/store/products/'.$product->id.'/attribute-values');
        $attrs->assertOk();
        $this->assertIsArray($attrs->json());

        ProductCustomizationField::query()->firstOrCreate(
            ['product_id' => $product->id, 'label' => 'W264 Engraving'],
            [
                'type' => 'text',
                'is_required' => false,
                'position' => 1,
                'config' => ['price_type' => 'fixed', 'price_amount' => 3, 'max_length' => 20],
            ],
        );

        $opts = $this->getJson('/api/v1/store/products/'.$product->id.'/custom-options');
        $opts->assertOk();
        $this->assertNotEmpty($opts->json());
        $optId = (int) ($opts->json('0.id') ?? 0);
        $this->assertGreaterThan(0, $optId);

        $one = $this->getJson('/api/v1/store/product_customizable_options/'.$optId);
        $one->assertOk();
        $this->assertSame($optId, (int) $one->json('id'));

        $price = $this->getJson('/api/v1/store/product_customizable_option_prices/'.$optId);
        $price->assertOk();
        $this->assertSame($optId, (int) $price->json('id'));

        $tr = $this->getJson('/api/v1/store/product_customizable_option_translations/'.$optId);
        $tr->assertOk();
        $this->assertNotEmpty((string) $tr->json('label'));
    }
}
