<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\SellerAttribute;
use App\Domain\Marketplace\Models\SellerBrand;
use App\Domain\Marketplace\Models\SellerFeature;
use App\Domain\Marketplace\Models\SellerSupplier;
use App\Domain\Marketplace\Models\SellerCategory;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 04 — seller catalog dual-write, ownership, moderation, public visibility.
 */
class MarketplaceCatalogTest extends MarketplaceTestCase
{

    /** @return array<string, mixed> */
    protected function applyPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => 'Ada Market',
            'shop_slug' => 'ada-market',
            'profile_name' => 'Ada Shop',
            'email' => 'ada.seller@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Handmade goods',
        ], $overrides);
    }

    protected function registerCustomer(string $email = 'ada@example.com'): Customer
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Seller',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{0: Seller, 1: Customer} */
    protected function approvedSeller(string $email = 'ada@example.com', array $apply = []): array
    {
        $this->postJson('/api/v1/auth/logout');
        $customer = $this->registerCustomer($email);
        $payload = $this->applyPayload(array_merge([
            'email' => str_replace('@', '.shop@', $email),
            'shop_slug' => 'shop-'.substr(md5($email), 0, 8),
        ], $apply));
        $this->postJson('/api/v1/account/seller/apply', $payload)->assertCreated();
        $seller = Seller::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        $this->actingAs($customer, 'customer');

        return [$seller->fresh(), $customer];
    }

    public function test_active_seller_can_create_and_list_own_products(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => false]);
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Clay mug',
            'sku' => 'mug-1',
            'price' => 18.5,
            'stock_qty' => 7,
            'is_enabled' => true,
            'seller_id' => 9999,
            'approval_status' => 'approved',
            'force_approved' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('product.approval_status', 'pending');

        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame($seller->id, (int) $product->seller_id);
        $this->assertFalse((bool) $product->is_enabled);
        $this->assertDatabaseHas('seller_products', [
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'approval_status' => SellerProduct::STATUS_PENDING,
            'name' => 'Clay mug',
        ]);

        $this->getJson('/api/v1/account/seller/products')
            ->assertOk()
            ->assertJsonPath('products.data.0.product_id', $product->id)
            ->assertJsonPath('products.data.0.approval_status', 'pending');
    }

    public function test_seller_cannot_manage_another_sellers_product(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $customerA] = $this->approvedSeller('a@example.com', [
            'shop_slug' => 'shop-a',
            'email' => 'a.shop@example.com',
        ]);
        $this->actingAs($customerA, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'A mug',
            'sku' => 'a-1',
            'price' => 10,
            'stock_qty' => 2,
        ])->assertCreated();
        $productA = Product::query()->where('seller_id', $sellerA->id)->firstOrFail();

        [$sellerB, $customerB] = $this->approvedSeller('b@example.com', [
            'shop_slug' => 'shop-b',
            'email' => 'b.shop@example.com',
        ]);
        $this->actingAs($customerB, 'customer');
        $this->getJson('/api/v1/account/seller/products/'.$productA->id)->assertNotFound();
        $this->patchJson('/api/v1/account/seller/products/'.$productA->id, [
            'name' => 'Hijacked',
            'price' => 1,
            'stock_qty' => 1,
            'seller_id' => $sellerB->id,
        ])->assertNotFound();
        $this->deleteJson('/api/v1/account/seller/products/'.$productA->id)->assertNotFound();

        $this->assertDatabaseHas('seller_products', [
            'product_id' => $productA->id,
            'seller_id' => $sellerA->id,
            'name' => 'A mug',
        ]);
    }

    public function test_pending_rejected_and_suspended_sellers_cannot_use_product_api(): void
    {
        $this->seed();
        $customer = $this->registerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Nope',
            'price' => 5,
            'stock_qty' => 1,
        ])->assertForbidden();

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'reject',
                'note' => 'Incomplete',
            ])->assertRedirect();
        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/products')->assertForbidden();

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'shop_slug' => 'ada-market-2',
        ]))->assertOk();
        $seller = $seller->fresh();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'suspend',
                'note' => 'Policy',
            ])->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Nope',
            'price' => 5,
            'stock_qty' => 1,
        ])->assertForbidden();
    }

    public function test_admin_moderation_approve_reject_and_seller_cannot_moderate(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => false]);
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Pending mug',
            'price' => 12,
            'stock_qty' => 2,
        ])->assertCreated();
        $copy = SellerProduct::query()->where('seller_id', $seller->id)->firstOrFail();

        // Customer/seller auth cannot change moderation state (admin guard required).
        $this->assertSame(SellerProduct::STATUS_PENDING, $copy->fresh()->approval_status);

        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');
        $this->post('/admin/multivendor/products/'.$copy->id.'/review', [
            'action' => 'reject',
        ])->assertSessionHasErrors('note');

        $this->post('/admin/multivendor/products/'.$copy->id.'/review', [
            'action' => 'reject',
            'note' => 'Blurry photos',
        ])->assertRedirect();
        $this->assertSame(SellerProduct::STATUS_REJECTED, $copy->fresh()->approval_status);
        $this->assertSame('Blurry photos', $copy->fresh()->approval_note);
        $this->assertSame($admin->id, (int) $copy->fresh()->reviewed_by);
        $this->assertFalse((bool) $copy->fresh()->product->is_enabled);

        // Resubmission after rejection (service-level: avoids admin/customer session clash in one test).
        $product = Product::query()->findOrFail($copy->product_id);
        $updated = app(\App\Domain\Marketplace\Services\SellerService::class)->saveProduct($seller->fresh(), [
            'name' => 'Pending mug v2',
            'price' => 13,
            'stock_qty' => 3,
            'is_enabled' => true,
        ], $product);
        $copy = SellerProduct::query()->where('product_id', $updated->id)->firstOrFail();
        $this->assertSame(SellerProduct::STATUS_PENDING, $copy->approval_status);
        $this->assertNull($copy->approval_note);
        $this->assertFalse((bool) $updated->fresh()->is_enabled);
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 0);

        $this->actingAs($admin, 'admin');
        $this->post('/admin/multivendor/products/'.$copy->id.'/review', [
            'action' => 'approve',
        ])->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->assertSame(SellerProduct::STATUS_APPROVED, $copy->fresh()->approval_status);
        $this->assertTrue((bool) $copy->fresh()->product->is_enabled);
        $this->assertTrue((bool) $copy->fresh()->product->available_for_order);
        $slug = $copy->fresh()->product->translation()?->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.available_for_order', true);
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 1);
    }

    public function test_approved_seller_product_is_purchasable_even_when_order_flag_drifted(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => false]);
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Drift mug',
            'price' => 12,
            'stock_qty' => 5,
        ])->assertCreated();
        $copy = SellerProduct::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/products/'.$copy->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        Product::query()->whereKey($copy->product_id)->update(['available_for_order' => false]);
        $slug = Product::query()->findOrFail($copy->product_id)->translation()?->slug;

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.available_for_order', true);

        $this->postJson('/api/v1/cart', ['product_id' => $copy->product_id, 'qty' => 1])->assertOk();
    }

    public function test_editing_approved_product_returns_to_pending_when_auto_approve_off(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => false]);
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Bowl',
            'price' => 20,
            'stock_qty' => 4,
        ])->assertCreated();
        $copy = SellerProduct::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/products/'.$copy->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->patchJson('/api/v1/account/seller/products/'.$copy->product_id, [
            'name' => 'Bowl Deluxe',
            'price' => 22,
            'stock_qty' => 4,
            'is_enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('product.approval_status', 'pending');
        $this->assertFalse((bool) Product::query()->find($copy->product_id)->is_enabled);
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 0);
    }

    public function test_public_catalog_hides_pending_rejected_and_inactive_seller_listings(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => false]);
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Hidden',
            'price' => 9,
            'stock_qty' => 1,
        ])->assertCreated();
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 0);

        $copy = SellerProduct::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/products/'.$copy->id.'/review', [
                'action' => 'reject',
                'note' => 'No',
            ])->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 0);

        $product = Product::query()->findOrFail($copy->product_id);
        app(\App\Domain\Marketplace\Services\SellerService::class)->saveProduct($seller->fresh(), [
            'name' => 'Shown',
            'price' => 9,
            'stock_qty' => 1,
            'is_enabled' => true,
        ], $product);
        $this->assertSame(SellerProduct::STATUS_PENDING, $copy->fresh()->approval_status);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/products/'.$copy->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 1);

        // Inactive shop is not publicly visible (Phase 03 rule, re-checked here).
        $seller->update(['is_active' => false]);
        $this->getJson('/api/v1/catalog/seller/ada-market')->assertNotFound();
    }

    public function test_delete_archives_when_order_history_exists(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Keep history',
            'sku' => 'hist-1',
            'price' => 15,
            'stock_qty' => 3,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'T-'.uniqid(),
            'email' => 'buyer@example.com',
            'status' => 'completed',
            'currency_code' => 'USD',
            'subtotal' => 15,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 15,
            'payment_status' => 'paid',
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Keep history',
            'qty' => 1,
            'unit_price' => 15,
            'total' => 15,
            'tax_amount' => 0,
        ]);

        $this->actingAs($customer, 'customer');
        $this->deleteJson('/api/v1/account/seller/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('action', 'archived');
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('seller_products', [
            'product_id' => $product->id,
            'is_enabled' => false,
        ]);
    }

    /** @return array{0: array<int, AttributeValue>, 1: int} */
    protected function combinationAxis(): array
    {
        $attributes = app(AttributeService::class);
        $color = $attributes->saveAttribute([
            'name' => 'Colour',
            'code' => 'colour',
            'type' => 'select',
            'is_enabled' => true,
        ]);
        foreach (['Black', 'White'] as $label) {
            $attributes->saveValue([
                'attribute_id' => $color->id,
                'value' => $label,
                'is_enabled' => true,
            ]);
        }

        $values = AttributeValue::query()->where('attribute_id', $color->id)->orderBy('id')->get()->all();

        return [$values, (int) $color->id];
    }

    public function test_seller_can_create_combination_product_with_axes_and_variants(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$values, $attributeId] = $this->combinationAxis();
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'type' => 'combination',
            'name' => 'Combo tee',
            'sku' => 'combo-1',
            'price' => 25,
            'combination_axes' => [
                ['attribute_id' => $attributeId, 'value_ids' => [$values[0]->id, $values[1]->id]],
            ],
            'combination_variants' => [
                [
                    'attribute_value_ids' => [$values[0]->id],
                    'sku' => 'COMBO-BLACK',
                    'price' => 25,
                    'stock_qty' => 4,
                    'is_default' => true,
                ],
                [
                    'attribute_value_ids' => [$values[1]->id],
                    'sku' => 'COMBO-WHITE',
                    'price' => 27,
                    'stock_qty' => 2,
                ],
            ],
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame('combination', $product->type);
        $this->assertFalse((bool) $product->manage_stock);
        $this->assertSame(2, $product->variants()->count());

        $this->getJson('/api/v1/account/seller/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('product.type', 'combination')
            ->assertJsonCount(1, 'product.combination_axes')
            ->assertJsonCount(2, 'product.combination_variants');
    }

    public function test_seller_can_create_virtual_product(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'type' => 'virtual',
            'name' => 'Download pack',
            'sku' => 'dl-1',
            'price' => 9.5,
            'visibility' => 'catalog',
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame('virtual', $product->type);
        $this->assertFalse((bool) $product->manage_stock);
        $this->assertSame('catalog', $product->visibility);
        $this->assertSame($seller->id, (int) $product->seller_id);
    }

    public function test_related_and_component_products_from_another_seller_are_rejected(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $customerA] = $this->approvedSeller('a@example.com', [
            'shop_slug' => 'shop-a',
            'email' => 'a.shop@example.com',
        ]);
        $this->actingAs($customerA, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'A mug',
            'sku' => 'a-1',
            'price' => 10,
            'stock_qty' => 2,
        ])->assertCreated();
        $productA = Product::query()->where('seller_id', $sellerA->id)->firstOrFail();

        [, $customerB] = $this->approvedSeller('b@example.com', [
            'shop_slug' => 'shop-b',
            'email' => 'b.shop@example.com',
        ]);
        $this->actingAs($customerB, 'customer');

        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'B bundle',
            'price' => 30,
            'related_ids' => [$productA->id],
        ])->assertStatus(422)->assertJsonValidationErrors('related_ids');

        $this->postJson('/api/v1/account/seller/products', [
            'type' => 'bundle',
            'name' => 'B bundle',
            'price' => 30,
            'components' => [
                ['id' => $productA->id, 'quantity' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('components.0.id');
    }

    public function test_seller_can_save_multilingual_product_translations(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        Language::query()->firstOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2]
        );
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);
        $default = Language::defaultCode();

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'price' => 14,
            'sku' => 'i18n-1',
            'translations' => [
                ['language_code' => $default, 'name' => 'Wool scarf', 'short_description' => 'Warm'],
                ['language_code' => 'fr', 'name' => 'Echarpe en laine', 'short_description' => 'Chaude'],
            ],
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame('Wool scarf', $product->translation($default)?->name);
        $this->assertSame('Echarpe en laine', $product->translation('fr')?->name);
        $this->assertStringStartsWith('ada-market-', (string) $product->translation('fr')?->slug);

        $this->getJson('/api/v1/account/seller/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('product.translations.fr.name', 'Echarpe en laine');
    }

    public function test_product_image_upload_is_scoped_to_the_owning_seller(): void
    {
        Storage::fake('public');
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $customerA] = $this->approvedSeller('a@example.com', [
            'shop_slug' => 'shop-a',
            'email' => 'a.shop@example.com',
        ]);
        $this->actingAs($customerA, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'A mug',
            'sku' => 'a-1',
            'price' => 10,
            'stock_qty' => 2,
        ])->assertCreated();
        $productA = Product::query()->where('seller_id', $sellerA->id)->firstOrFail();

        $this->post('/api/v1/account/seller/products/'.$productA->id.'/images', [
            'images' => [UploadedFile::fake()->image('mug.jpg')],
        ])->assertOk();
        $image = $productA->fresh()->images()->firstOrFail();
        $this->assertSame($image->path, $productA->fresh()->cover_path);

        [, $customerB] = $this->approvedSeller('b@example.com', [
            'shop_slug' => 'shop-b',
            'email' => 'b.shop@example.com',
        ]);
        $this->actingAs($customerB, 'customer');
        $this->post('/api/v1/account/seller/products/'.$productA->id.'/images', [
            'images' => [UploadedFile::fake()->image('hijack.jpg')],
        ])->assertNotFound();
        $this->deleteJson('/api/v1/account/seller/products/'.$productA->id.'/images/'.$image->id)
            ->assertNotFound();
        $this->patchJson('/api/v1/account/seller/products/'.$productA->id.'/images/order', [
            'image_order' => [$image->id],
        ])->assertNotFound();

        $this->assertSame(1, $productA->fresh()->images()->count());
    }

    public function test_unauthenticated_and_non_seller_denied(): void
    {
        $this->seed();
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'X',
            'price' => 1,
            'stock_qty' => 1,
        ])->assertUnauthorized();

        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'X',
            'price' => 1,
            'stock_qty' => 1,
        ])->assertForbidden();
    }

    public function test_seller_product_list_supports_search_filter_sort_and_pagination(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $customer] = $this->approvedSeller('list@example.com', [
            'shop_slug' => 'list-shop',
            'email' => 'list.shop@example.com',
        ]);
        $this->actingAs($customer, 'customer');

        foreach ([
            ['name' => 'Alpha lamp', 'sku' => 'AL-1', 'price' => 10, 'stock_qty' => 5],
            ['name' => 'Beta chair', 'sku' => 'BC-2', 'price' => 20, 'stock_qty' => 0],
            ['name' => 'Gamma desk', 'sku' => 'GD-3', 'price' => 30, 'stock_qty' => 3],
        ] as $payload) {
            $this->postJson('/api/v1/account/seller/products', $payload)->assertCreated();
        }

        $this->getJson('/api/v1/account/seller/products?name=Alpha')
            ->assertOk()
            ->assertJsonPath('products.total', 1)
            ->assertJsonPath('products.data.0.name', 'Alpha lamp');

        $this->getJson('/api/v1/account/seller/products?status=out_of_stock')
            ->assertOk()
            ->assertJsonPath('products.total', 1)
            ->assertJsonPath('products.data.0.sku', 'LIST-SHOP-BC-2');

        $this->getJson('/api/v1/account/seller/products?sort=price&dir=asc')
            ->assertOk()
            ->assertJsonPath('products.data.0.price', 10)
            ->assertJsonPath('products.data.2.price', 30);

        $this->getJson('/api/v1/account/seller/products?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('products.per_page', 2)
            ->assertJsonPath('products.current_page', 2)
            ->assertJsonPath('products.total', 3);
    }

    public function test_seller_product_list_search_and_bulk_actions_are_isolated(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $customerA] = $this->approvedSeller('iso-a@example.com', [
            'shop_slug' => 'iso-a',
            'email' => 'iso-a.shop@example.com',
        ]);
        $this->actingAs($customerA, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Seller A mug',
            'sku' => 'A-MUG',
            'price' => 12,
            'stock_qty' => 4,
        ])->assertCreated();
        $productA = Product::query()->where('seller_id', $sellerA->id)->firstOrFail();

        [$sellerB, $customerB] = $this->approvedSeller('iso-b@example.com', [
            'shop_slug' => 'iso-b',
            'email' => 'iso-b.shop@example.com',
        ]);
        $this->actingAs($customerB, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Seller B vase',
            'sku' => 'B-VASE',
            'price' => 18,
            'stock_qty' => 2,
        ])->assertCreated();
        $productB = Product::query()->where('seller_id', $sellerB->id)->firstOrFail();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'iso-a@example.com',
            'password' => 'password123',
        ])->assertOk();
        $this->getJson('/api/v1/account/seller/products?name='.urlencode('Seller A'))
            ->assertOk()
            ->assertJsonPath('products.total', 1);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'iso-b@example.com',
            'password' => 'password123',
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/products/bulk-status', [
            'ids' => [$productA->id],
            'enabled' => false,
        ])->assertStatus(422);

        $this->postJson('/api/v1/account/seller/products/bulk-delete', [
            'ids' => [$productA->id],
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'iso-a@example.com',
            'password' => 'password123',
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/products/bulk-status', [
            'ids' => [$productA->id],
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertFalse((bool) $productA->fresh()->is_enabled);
        $this->assertTrue((bool) $productB->fresh()->is_enabled);
    }

    public function test_seller_product_export_respects_filters_and_isolation(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $customerA] = $this->approvedSeller('exp-a@example.com', [
            'shop_slug' => 'exp-a',
            'email' => 'exp-a.shop@example.com',
        ]);
        $this->actingAs($customerA, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Export Alpha',
            'sku' => 'EXP-A',
            'price' => 11,
            'stock_qty' => 1,
        ])->assertCreated();

        [$sellerB] = $this->approvedSeller('exp-b@example.com', [
            'shop_slug' => 'exp-b',
            'email' => 'exp-b.shop@example.com',
        ]);
        app(SellerService::class)->saveProduct($sellerB, [
            'name' => 'Export Beta',
            'sku' => 'EXP-B',
            'price' => 22,
            'stock_qty' => 2,
            'is_enabled' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'exp-a@example.com',
            'password' => 'password123',
        ])->assertOk();
        $response = $this->get('/api/v1/account/seller/exports/products?sku=EXP-A');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('EXP-A', $csv);
        $this->assertStringNotContainsString('EXP-B', $csv);
    }

    public function test_seller_product_import_is_isolated_and_rejects_foreign_sku(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $customerA] = $this->approvedSeller('imp-a@example.com', [
            'shop_slug' => 'imp-a',
            'email' => 'imp-a.shop@example.com',
        ]);
        [$sellerB] = $this->approvedSeller('imp-b@example.com', [
            'shop_slug' => 'imp-b',
            'email' => 'imp-b.shop@example.com',
        ]);
        app(SellerService::class)->saveProduct($sellerB, [
            'name' => 'Owned by B',
            'sku' => 'IMP-B',
            'price' => 9,
            'stock_qty' => 1,
            'is_enabled' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'imp-a@example.com',
            'password' => 'password123',
        ])->assertOk();

        $ok = UploadedFile::fake()->createWithContent(
            'seller-products.csv',
            "sku;name;price;stock_qty\nIMP-A;Imported Alpha;15;4\n"
        );
        $this->post('/api/v1/account/seller/products/import', [
            'file' => $ok,
            'delimiter' => ';',
            'multivalue_separator' => ',',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertDatabaseHas('products', [
            'seller_id' => $sellerA->id,
            'sku' => 'IMP-A-IMP-A',
            'price' => 15,
        ]);
        $this->assertDatabaseHas('seller_products', [
            'seller_id' => $sellerA->id,
            'sku' => 'IMP-A-IMP-A',
            'name' => 'Imported Alpha',
        ]);

        $foreignSku = Product::query()->where('seller_id', $sellerB->id)->value('sku');
        $blocked = UploadedFile::fake()->createWithContent(
            'seller-products-blocked.csv',
            "sku;name;price;stock_qty\n{$foreignSku};Hijack attempt;1;1\n"
        );
        $this->post('/api/v1/account/seller/products/import', [
            'file' => $blocked,
            'delimiter' => ';',
            'multivalue_separator' => ',',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('seller_products', [
            'seller_id' => $sellerA->id,
            'sku' => $foreignSku,
        ]);
        $this->assertDatabaseHas('seller_products', [
            'seller_id' => $sellerB->id,
            'sku' => $foreignSku,
            'name' => 'Owned by B',
        ]);
    }

    public function test_active_seller_can_access_catalog_taxonomy_endpoints(): void
    {
        $this->seed();
        [$seller, $customer] = $this->approvedSeller('catalog-tax@example.com', [
            'shop_slug' => 'catalog-tax-shop',
            'email' => 'catalog.tax@example.com',
        ]);
        $this->actingAs($customer, 'customer');

        $this->getJson('/api/v1/account/seller/categories')->assertOk()->assertJsonStructure([
            'tree',
            'counts',
            'parent_options',
            'languages',
            'shop_url',
            'customer_groups',
        ]);
        $this->getJson('/api/v1/account/seller/brands')->assertOk()->assertJsonStructure([
            'rows',
            'counts',
            'languages',
            'shop_url',
            'countries',
        ]);
        $this->getJson('/api/v1/account/seller/suppliers')->assertOk()->assertJsonStructure([
            'rows',
            'counts',
            'languages',
        ]);
        $this->getJson('/api/v1/account/seller/features')->assertOk()->assertJsonStructure([
            'rows',
            'counts',
            'languages',
            'type_options',
        ]);
        $this->getJson('/api/v1/account/seller/options')
            ->assertOk()
            ->assertJsonStructure(['combination_attributes', 'categories', 'brands']);

        $categoryId = $this->postJson('/api/v1/account/seller/categories', [
            'name' => 'Seller Clay',
            'is_enabled' => true,
        ])->assertCreated()->json('row.id');

        $this->assertDatabaseHas('seller_categories', [
            'id' => $categoryId,
            'seller_id' => $seller->id,
            'name' => 'Seller Clay',
        ]);
    }

    public function test_seller_can_create_category_with_translations_seo_and_customer_groups(): void
    {
        Storage::fake('public');
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        [$seller, $customer] = $this->approvedSeller('cat-full@example.com', [
            'shop_slug' => 'cat-full-shop',
            'email' => 'cat.full@example.com',
        ]);
        $default = Language::defaultCode();
        $groupIds = CustomerGroup::catalog(true)->take(2)->pluck('id')->all();
        $this->actingAs($customer, 'customer');

        $parentId = $this->postJson('/api/v1/account/seller/categories', [
            'name' => 'Parent Cat',
            'slug' => 'parent-cat',
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'Parent Cat',
                    'slug' => 'parent-cat',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $response = $this->postJson('/api/v1/account/seller/categories', [
            'name' => 'Seller Clay',
            'slug' => 'seller-clay',
            'parent_id' => $parentId,
            'description' => '<p>English description</p>',
            'meta_title' => 'Clay SEO',
            'meta_description' => 'Clay meta',
            'meta_keywords' => 'clay, pottery',
            'canonical_url' => 'https://example.test/clay',
            'customer_group_ids' => $groupIds,
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'Seller Clay',
                    'slug' => 'seller-clay',
                    'description' => '<p>English description</p>',
                    'meta_title' => 'Clay SEO',
                    'meta_description' => 'Clay meta',
                    'meta_keywords' => 'clay, pottery',
                    'canonical_url' => 'https://example.test/clay',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Argile vendeur',
                    'slug' => 'argile-vendeur',
                    'description' => '<p>Description FR</p>',
                    'meta_title' => 'SEO FR',
                    'meta_description' => 'Meta FR',
                ],
            ],
        ])->assertCreated();

        $categoryId = $response->json('row.id');
        $sellerCategory = SellerCategory::query()->whereKey($categoryId)->firstOrFail();
        $core = Category::query()->whereKey($sellerCategory->category_id)->firstOrFail();

        $this->assertSame($parentId, (int) $sellerCategory->parent_id);
        $this->assertSame($groupIds, Category::normalizeCustomerGroupIds($core->customer_group_ids));
        $this->assertStringStartsWith('cat-full-shop-', (string) $core->translation($default)?->slug);
        $this->assertStringStartsWith('cat-full-shop-', (string) $core->translation('fr')?->slug);

        $this->getJson("/api/v1/account/seller/categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('category.slug', 'seller-clay')
            ->assertJsonFragment(['slug' => 'seller-clay', 'language_code' => $default])
            ->assertJsonFragment(['name' => 'Argile vendeur', 'language_code' => 'fr'])
            ->assertJsonStructure(['languages', 'customer_groups', 'shop_url']);
    }

    public function test_seller_category_requires_default_language_name(): void
    {
        $this->seed();
        [, $customer] = $this->approvedSeller('cat-req@example.com', [
            'shop_slug' => 'cat-req-shop',
            'email' => 'cat.req@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $this->postJson('/api/v1/account/seller/categories', [
            'name' => '',
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => '',
                    'slug' => '',
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors("translations.$default.name");
    }

    public function test_seller_can_create_brand_with_translations_and_seo(): void
    {
        Storage::fake('public');
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        [$seller, $customer] = $this->approvedSeller('brand-full@example.com', [
            'shop_slug' => 'brand-full-shop',
            'email' => 'brand.full@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $brandId = $this->postJson('/api/v1/account/seller/brands', [
            'name' => 'Seller Brand',
            'slug' => 'seller-brand',
            'short_description' => 'Short copy',
            'tagline' => 'Quality goods',
            'website' => 'https://brand.example.com',
            'country_code' => 'US',
            'established_year' => 2010,
            'email' => 'hello@brand.example.com',
            'meta_title' => 'Brand OG',
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'Seller Brand',
                    'slug' => 'seller-brand',
                    'short_description' => 'Short copy',
                    'tagline' => 'Quality goods',
                    'meta_title' => 'Brand OG',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Marque vendeur',
                    'slug' => 'marque-vendeur',
                    'meta_title' => 'OG FR',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $sellerBrand = SellerBrand::query()->whereKey($brandId)->firstOrFail();
        $core = Brand::query()->whereKey($sellerBrand->brand_id)->firstOrFail();

        $this->assertSame('Seller Brand', $sellerBrand->name);
        $this->assertSame('Brand OG', $core->translation($default)?->meta_title);
        $this->assertSame('OG FR', $core->translation('fr')?->meta_title);
        $this->assertStringStartsWith('brand-full-shop-', (string) $core->translation($default)?->slug);
        $this->assertSame('https://brand.example.com', $core->website);
        $this->assertSame('US', $core->country_code);

        $this->getJson("/api/v1/account/seller/brands/{$brandId}")
            ->assertOk()
            ->assertJsonPath('brand.slug', 'seller-brand')
            ->assertJsonFragment(['slug' => 'seller-brand', 'language_code' => $default])
            ->assertJsonFragment(['name' => 'Marque vendeur', 'language_code' => 'fr'])
            ->assertJsonStructure(['languages', 'countries', 'shop_url']);
    }

    public function test_seller_brand_requires_default_language_name(): void
    {
        $this->seed();
        [, $customer] = $this->approvedSeller('brand-req@example.com', [
            'shop_slug' => 'brand-req-shop',
            'email' => 'brand.req@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $this->postJson('/api/v1/account/seller/brands', [
            'name' => '',
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => '',
                    'slug' => '',
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors("translations.$default.name");
    }

    public function test_seller_can_create_supplier_with_translations_and_contact(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        [$seller, $customer] = $this->approvedSeller('supplier-full@example.com', [
            'shop_slug' => 'supplier-full-shop',
            'email' => 'supplier.full@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $supplierId = $this->postJson('/api/v1/account/seller/suppliers', [
            'name' => 'Acme Wholesale',
            'description' => 'Bulk goods supplier',
            'email' => 'orders@acme.test',
            'phone' => '+1 555 0100',
            'website' => 'https://acme.test',
            'city' => 'Austin',
            'country' => 'US',
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'Acme Wholesale',
                    'description' => 'Bulk goods supplier',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Grossiste Acme',
                    'description' => 'Fournisseur en gros',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $sellerSupplier = SellerSupplier::query()->whereKey($supplierId)->firstOrFail();
        $core = Supplier::query()->whereKey($sellerSupplier->supplier_id)->firstOrFail();

        $this->assertSame('Acme Wholesale', $sellerSupplier->name);
        $this->assertSame('orders@acme.test', $core->email);
        $this->assertSame('Grossiste Acme', $core->translation('fr')?->name);

        $this->getJson("/api/v1/account/seller/suppliers/{$supplierId}")
            ->assertOk()
            ->assertJsonPath('supplier.name', 'Acme Wholesale')
            ->assertJsonFragment(['name' => 'Grossiste Acme', 'language_code' => 'fr'])
            ->assertJsonStructure(['languages']);
    }

    public function test_seller_can_create_feature_with_translations(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        [$seller, $customer] = $this->approvedSeller('feature-full@example.com', [
            'shop_slug' => 'feature-full-shop',
            'email' => 'feature.full@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $featureId = $this->postJson('/api/v1/account/seller/features', [
            'name' => 'RAM',
            'description' => '<p>Memory size</p>',
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'RAM',
                    'description' => '<p>Memory size</p>',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Mémoire RAM',
                    'description' => '<p>Taille de mémoire</p>',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $sellerFeature = SellerFeature::query()->whereKey($featureId)->firstOrFail();
        $core = Feature::query()->whereKey($sellerFeature->feature_id)->firstOrFail();

        $this->assertSame('RAM', $sellerFeature->name);
        $this->assertSame('Mémoire RAM', $core->translation('fr')?->name);

        $this->getJson("/api/v1/account/seller/features/{$featureId}")
            ->assertOk()
            ->assertJsonPath('feature.name', 'RAM')
            ->assertJsonFragment(['name' => 'Mémoire RAM', 'language_code' => 'fr'])
            ->assertJsonStructure(['languages', 'type_options']);
    }

    public function test_seller_can_create_feature_value_with_translations(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        [$seller, $customer] = $this->approvedSeller('feature-value@example.com', [
            'shop_slug' => 'feature-value-shop',
            'email' => 'feature.value@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $sellerFeatureId = $this->postJson('/api/v1/account/seller/features', [
            'name' => 'RAM',
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'RAM',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $valueId = $this->postJson('/api/v1/account/seller/feature-values', [
            'feature_id' => $sellerFeatureId,
            'value' => '8 GB',
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'value' => '8 GB',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'value' => '8 Go',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $this->getJson('/api/v1/account/seller/feature-values?feature_id='.$sellerFeatureId)
            ->assertOk()
            ->assertJsonStructure(['rows', 'counts', 'feature', 'features', 'languages'])
            ->assertJsonPath('counts.all', 1);

        $this->getJson("/api/v1/account/seller/feature-values/{$valueId}")
            ->assertOk()
            ->assertJsonPath('feature_value.value', '8 GB')
            ->assertJsonFragment(['value' => '8 Go', 'language_code' => 'fr']);
    }

    public function test_seller_can_create_attribute_with_translations(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        [$seller, $customer] = $this->approvedSeller('attribute-full@example.com', [
            'shop_slug' => 'attribute-full-shop',
            'email' => 'attribute.full@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $attributeId = $this->postJson('/api/v1/account/seller/attributes', [
            'name' => 'Color',
            'description' => '<p>Product color</p>',
            'type' => 'select',
            'supports_swatches' => true,
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'Color',
                    'description' => '<p>Product color</p>',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Couleur',
                    'description' => '<p>Couleur du produit</p>',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $sellerAttribute = SellerAttribute::query()->whereKey($attributeId)->firstOrFail();
        $core = CatalogAttribute::query()->whereKey($sellerAttribute->attribute_id)->firstOrFail();

        $this->assertSame('Color', $sellerAttribute->name);
        $this->assertTrue($core->supports_swatches);
        $this->assertSame('Couleur', $core->translation('fr')?->name);

        $this->getJson("/api/v1/account/seller/attributes/{$attributeId}")
            ->assertOk()
            ->assertJsonPath('attribute.name', 'Color')
            ->assertJsonFragment(['name' => 'Couleur', 'language_code' => 'fr'])
            ->assertJsonStructure(['languages', 'type_options']);
    }

    public function test_seller_can_create_attribute_value_with_translations(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        [$seller, $customer] = $this->approvedSeller('attribute-value@example.com', [
            'shop_slug' => 'attribute-value-shop',
            'email' => 'attribute.value@example.com',
        ]);
        $default = Language::defaultCode();
        $this->actingAs($customer, 'customer');

        $sellerAttributeId = $this->postJson('/api/v1/account/seller/attributes', [
            'name' => 'Color',
            'type' => 'select',
            'supports_swatches' => true,
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'name' => 'Color',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $valueId = $this->postJson('/api/v1/account/seller/attribute-values', [
            'attribute_id' => $sellerAttributeId,
            'value' => 'Black',
            'swatch_type' => 'color',
            'color_code' => '#000000',
            'is_enabled' => true,
            'translations' => [
                $default => [
                    'language_code' => $default,
                    'value' => 'Black',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'value' => 'Noir',
                ],
            ],
        ])->assertCreated()->json('row.id');

        $this->getJson('/api/v1/account/seller/attribute-values?attribute_id='.$sellerAttributeId)
            ->assertOk()
            ->assertJsonStructure(['rows', 'counts', 'attribute', 'attributes', 'languages', 'swatch_types'])
            ->assertJsonPath('counts.all', 1);

        $this->getJson("/api/v1/account/seller/attribute-values/{$valueId}")
            ->assertOk()
            ->assertJsonPath('attribute_value.value', 'Black')
            ->assertJsonFragment(['value' => 'Noir', 'language_code' => 'fr']);
    }

    public function test_seller_cannot_modify_other_seller_category(): void
    {
        $this->seed();
        [$sellerA, $customerA] = $this->approvedSeller('cat-a@example.com', [
            'shop_slug' => 'cat-a-shop',
            'email' => 'cat.a@example.com',
        ]);
        [, $customerB] = $this->approvedSeller('cat-b@example.com', [
            'shop_slug' => 'cat-b-shop',
            'email' => 'cat.b@example.com',
        ]);

        $this->actingAs($customerB, 'customer');
        $categoryId = $this->postJson('/api/v1/account/seller/categories', [
            'name' => 'Category B',
            'is_enabled' => true,
        ])->assertCreated()->json('row.id');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'cat-a@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->getJson("/api/v1/account/seller/categories/{$categoryId}")->assertNotFound();
        $this->patchJson("/api/v1/account/seller/categories/{$categoryId}", [
            'name' => 'Hijacked',
            'is_enabled' => true,
        ])->assertNotFound();
        $this->deleteJson("/api/v1/account/seller/categories/{$categoryId}")->assertNotFound();

        $this->assertDatabaseHas('seller_categories', [
            'id' => $categoryId,
            'name' => 'Category B',
        ]);
    }

    public function test_seller_cannot_set_descendant_category_as_parent(): void
    {
        $this->seed();
        [, $customer] = $this->approvedSeller('cat-tree@example.com', [
            'shop_slug' => 'cat-tree-shop',
            'email' => 'cat.tree@example.com',
        ]);
        $this->actingAs($customer, 'customer');

        $rootId = $this->postJson('/api/v1/account/seller/categories', [
            'name' => 'Root',
            'is_enabled' => true,
        ])->assertCreated()->json('row.id');

        $childId = $this->postJson('/api/v1/account/seller/categories', [
            'name' => 'Child',
            'parent_id' => $rootId,
            'is_enabled' => true,
        ])->assertCreated()->json('row.id');

        $this->patchJson("/api/v1/account/seller/categories/{$rootId}", [
            'name' => 'Root',
            'parent_id' => $childId,
            'is_enabled' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_seller_cannot_modify_other_seller_taxonomy(): void
    {
        $this->seed();
        [$sellerA, $customerA] = $this->approvedSeller('tax-a@example.com', [
            'shop_slug' => 'tax-a-shop',
            'email' => 'tax.a@example.com',
        ]);
        [$sellerB, $customerB] = $this->approvedSeller('tax-b@example.com', [
            'shop_slug' => 'tax-b-shop',
            'email' => 'tax.b@example.com',
        ]);

        $this->actingAs($customerB, 'customer');
        $brandId = $this->postJson('/api/v1/account/seller/brands', [
            'name' => 'Brand B',
            'is_enabled' => true,
        ])->assertCreated()->json('row.id');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'tax-a@example.com',
            'password' => 'password123',
        ])->assertOk();
        $this->patchJson("/api/v1/account/seller/brands/{$brandId}", [
            'name' => 'Hijacked',
            'is_enabled' => true,
        ])->assertNotFound();

        $this->deleteJson("/api/v1/account/seller/brands/{$brandId}")->assertNotFound();

        $this->assertDatabaseHas('seller_brands', [
            'id' => $brandId,
            'seller_id' => $sellerB->id,
            'name' => 'Brand B',
        ]);
    }

    public function test_pending_seller_cannot_access_catalog_management_api(): void
    {
        $this->seed();
        $customer = $this->registerCustomer('pending-tax@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'pending.tax@example.com',
            'shop_slug' => 'pending-tax-shop',
        ]))->assertCreated();

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/products')->assertForbidden();
        $this->getJson('/api/v1/account/seller/categories')->assertForbidden();
        $this->getJson('/api/v1/account/seller/brands')->assertForbidden();
    }
}
