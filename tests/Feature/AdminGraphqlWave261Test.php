<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave261Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_customizable_files_and_translation_iris(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        Storage::fake('local');
        Storage::fake('public');

        $channel = $this->getJson('/api/v1/store/stores?per_page=5');
        $channel->assertOk();
        $translationIri = (string) ($channel->json('0.translation') ?? '');
        $this->assertStringContainsString('/api/v1/store/store_translations/', $translationIri);
        $translations = $channel->json('0.translations') ?? [];
        $this->assertIsArray($translations);
        $this->assertNotEmpty($translations);

        $trList = $this->getJson('/api/v1/store/store_translations?per_page=20');
        $trList->assertOk();
        $this->assertNotEmpty($trList->json());
        $trId = (int) ($trList->json('0.id') ?? 0);
        $this->assertGreaterThan(0, $trId);
        $trShow = $this->getJson('/api/v1/store/store_translations/'.$trId);
        $trShow->assertOk();
        $this->assertSame($trId, (int) $trShow->json('id'));
        $this->assertNotEmpty((string) $trShow->json('locale'));
        $this->assertSame((int) Store::query()->where('is_enabled', true)->value('id'), (int) $trShow->json('storeId'));

        $attr = Feature::query()->where('is_enabled', true)->orderBy('id')->first();
        if ($attr) {
            $attrTr = $this->getJson('/api/v1/store/attribute_translations/'.$attr->id);
            $attrTr->assertOk();
            $this->assertSame((int) $attr->id, (int) $attrTr->json('attributeId'));
            $list = $this->getJson('/api/v1/store/attribute_translations?per_page=5');
            $list->assertOk();
        }

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W261-FILE-'.uniqid(),
            'price' => 55,
            'stock_qty' => 10,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
            'customization_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave261 File Product',
            'slug' => 'wave261-file-'.uniqid(),
        ]);
        $field = ProductCustomizationField::query()->create([
            'product_id' => $product->id,
            'type' => 'image',
            'label' => 'Design upload',
            'is_required' => true,
            'position' => 1,
            'config' => [
                'allowed_file_types' => 'jpg,jpeg,png,webp',
                'max_file_size_kb' => 2048,
                'price_type' => 'none',
                'price_amount' => 0,
            ],
        ]);

        $tokenRes = $this->post('/api/v1/store/customizable-option-files', [
            'product_id' => $product->id,
            'option_id' => $field->id,
            'file' => UploadedFile::fake()->image('logo.png', 40, 40),
        ], ['Accept' => 'application/json']);
        $tokenRes->assertCreated();
        $token = (string) $tokenRes->json('token');
        $this->assertNotSame('', $token);
        $this->assertSame((int) $field->id, (int) $tokenRes->json('optionId'));
        $this->assertSame('logo.png', (string) $tokenRes->json('fileName'));

        $cart = $this->postJson('/api/v1/store/cart-tokens', []);
        $cart->assertCreated();
        $headers = ['Authorization' => 'Bearer '.(string) $cart->json('cartToken')];

        $add = $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $product->id,
            'quantity' => 1,
            'customizableOptions' => [
                (string) $field->id => [$token],
            ],
        ]);
        $add->assertOk();
        $this->assertTrue((bool) $add->json('success'));
        $this->assertGreaterThanOrEqual(1, (int) $add->json('itemsCount'));

        // Token is single-use
        $reuse = $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $product->id,
            'quantity' => 1,
            'customizableOptions' => [
                (string) $field->id => [$token],
            ],
        ]);
        $reuse->assertStatus(422);
    }
}
