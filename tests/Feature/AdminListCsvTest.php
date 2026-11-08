<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Company;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Order\Models\Order;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingZone;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminListCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_csvs_download_and_import_on_crud_lists(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $simple = [
            ['/admin/discounts/import/sample', '/admin/discounts/import', DiscountRule::class, 'WELCOME10'],
            ['/admin/shipping/import/sample', '/admin/shipping/import', ShippingMethod::class, 'csv-flat'],
            ['/admin/newsletter/import/sample', '/admin/newsletter/import', NewsletterSubscriber::class, 'newsletter.one@example.com'],
            ['/admin/warehouses/import/sample', '/admin/warehouses/import', null, null],
            ['/admin/companies/import/sample', '/admin/companies/import', Company::class, 'Northwind Trading'],
            ['/admin/cms/import/sample', '/admin/cms/import', CmsPage::class, null],
            ['/admin/blocks/import/sample', '/admin/blocks/import', null, null],
            ['/admin/customer-groups/import/sample', '/admin/customer-groups/import', null, null],
            ['/admin/localization/languages/import/sample', '/admin/localization/languages/import', null, null],
            ['/admin/localization/currencies/import/sample', '/admin/localization/currencies/import', null, null],
            ['/admin/localization/countries/import/sample', '/admin/localization/countries/import', null, null],
            ['/admin/order-stages/import/sample', '/admin/order-stages/import', null, null],
            ['/admin/payments/import/sample', '/admin/payments/import', null, null],
            ['/admin/tax/import/sample', '/admin/tax/import', null, null],
            ['/admin/shipping/zones/import/sample', '/admin/shipping/zones/import', ShippingZone::class, 'CSV EU'],
            ['/admin/cms/categories/import/sample', '/admin/cms/categories/import', CmsCategory::class, 'Policies'],
        ];

        foreach ($simple as [$sampleUrl, $importUrl, $model, $needle]) {
            $sample = $this->get($sampleUrl);
            $sample->assertOk();
            $csv = $sample->streamedContent();
            $this->assertNotSame('', trim($csv));
            $this->assertStringContainsString(';', explode("\n", $csv)[0]);

            $this->post($importUrl, [
                'file' => UploadedFile::fake()->createWithContent('sample.csv', $csv),
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('discount_rules', ['code' => 'WELCOME10']);
        $this->assertDatabaseHas('shipping_methods', ['code' => 'csv-flat']);
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'newsletter.one@example.com']);
        $this->assertDatabaseHas('companies', ['name' => 'Northwind Trading']);
        $this->assertTrue(CmsPage::query()->whereHas('translations', fn ($q) => $q->where('slug', 'shipping-policy'))->exists());
        $this->assertDatabaseHas('shipping_zones', ['name' => 'CSV EU']);
        $this->assertDatabaseHas('cms_categories', ['slug' => 'policies']);
    }

    public function test_customer_order_and_address_sample_csvs_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $customers = $this->get('/admin/customers/import/sample')->assertOk()->streamedContent();
        $this->post('/admin/customers/import', [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $customers),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers', ['email' => 'jane.shopper@example.com']);

        $orders = $this->get('/admin/orders/import/sample')->assertOk()->streamedContent();
        $this->post('/admin/orders/import', [
            'file' => UploadedFile::fake()->createWithContent('orders.csv', $orders),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Order::query()->where('number', 'SO-CSV-1001')->exists());

        $addresses = $this->get('/admin/addresses/import/sample')->assertOk()->streamedContent();
        $this->post('/admin/addresses/import', [
            'file' => UploadedFile::fake()->createWithContent('addresses.csv', $addresses),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_addresses', ['line1' => '100 Market Street']);
    }

    public function test_feature_attribute_review_and_inventory_samples_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $feature = Feature::query()->create([
            'code' => 'color',
            'type' => 'color',
            'is_enabled' => true,
            'position' => 1,
        ]);
        $feature->translations()->create(['language_code' => 'en', 'name' => 'Color']);

        $attribute = CatalogAttribute::query()->create([
            'code' => 'size',
            'type' => 'select',
            'is_enabled' => true,
            'position' => 1,
        ]);
        $attribute->translations()->create(['language_code' => 'en', 'name' => 'Size']);

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'DEMO-SKU',
            'price' => 12,
            'stock_qty' => 5,
            'is_enabled' => true,
            'visibility' => 'catalog_search',
            'available_for_order' => true,
            'show_price' => true,
            'manage_stock' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Demo product',
            'slug' => 'demo-sku',
        ]);

        Customer::factory()->create([
            'email' => 'jane.shopper@example.com',
            'name' => 'Jane Shopper',
        ]);
        Customer::factory()->create([
            'email' => 'alex.wholesale@example.com',
            'name' => 'Alex Wholesale',
        ]);

        foreach ([
            '/admin/features/values',
            '/admin/attributes/values',
            '/admin/reviews',
            '/admin/questions',
            '/admin/inventory',
        ] as $base) {
            $csv = $this->get($base.'/import/sample')->assertOk()->streamedContent();
            $this->post($base.'/import', [
                'file' => UploadedFile::fake()->createWithContent('sample.csv', $csv),
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('feature_values', ['code' => 'csv-teal']);
        $this->assertDatabaseHas('attribute_values', ['code' => 'csv-xl']);
        $this->assertGreaterThan(0, Review::query()->count());
        $this->assertSame(25, (int) $product->fresh()->availableStock());
    }

    public function test_document_lists_export_without_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/invoices/export')->assertOk();
        $this->get('/admin/credit-slips/export')->assertOk();
        $this->get('/admin/delivery-slips/export')->assertOk();
    }
}
