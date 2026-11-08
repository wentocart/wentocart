<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\PriceHistorySchema;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\PriceHistoryRecorder;
use App\Domain\Settings\OmnibusSettings;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OmnibusPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_records_price_on_product_save(): void
    {
        ShopSetting::put('commerce.omnibus_enabled', true);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $newPrice = round((float) $product->price + 11.11, 2);
        $product->update(['price' => $newPrice]);

        $row = DB::table('product_price_history')
            ->where('product_id', $product->id)
            ->where('recorded_on', now()->toDateString())
            ->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta($newPrice, (float) $row->price, 0.001);
    }

    public function test_lowest_price_last_days(): void
    {
        PriceHistorySchema::ensure();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $recorder = app(PriceHistoryRecorder::class);

        Carbon::setTestNow('2026-01-01 12:00:00');
        $product->update(['price' => 100.00]);

        Carbon::setTestNow('2026-01-10 12:00:00');
        DB::table('product_price_history')->insert([
            'product_id' => $product->id,
            'store_id' => null,
            'price' => 75.00,
            'recorded_on' => '2026-01-10',
            'recorded_at' => now(),
        ]);

        Carbon::setTestNow('2026-02-01 12:00:00');
        $lowest = $recorder->lowestPriceLastDays((int) $product->id, 30);

        $this->assertNotNull($lowest);
        $this->assertEqualsWithDelta(75.00, $lowest, 0.001);

        Carbon::setTestNow();
    }

    public function test_api_exposes_omnibus_when_on_sale_and_enabled(): void
    {
        ShopSetting::put('pricing.sale_enabled', true);
        ShopSetting::put('commerce.omnibus_enabled', true);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $slug = $product->translation('en')?->slug;
        $this->assertNotEmpty($slug);

        Carbon::setTestNow('2026-01-05 10:00:00');
        $product->update(['price' => 100.00]);

        Carbon::setTestNow('2026-02-01 10:00:00');
        $product->update([
            'price' => 100.00,
            'sale_price' => 70.00,
            'sale_price_type' => 'fixed',
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'is_on_sale' => true,
        ]);

        $presented = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertSame(OmnibusSettings::windowDays(), $presented['omnibus_window_days']);
        $this->assertEqualsWithDelta(100.0, (float) $presented['omnibus_lowest_price'], 0.001);

        $json = $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->json('product');

        $this->assertSame(OmnibusSettings::windowDays(), $json['omnibus_window_days']);
        $this->assertEqualsWithDelta(100.0, (float) $json['omnibus_lowest_price'], 0.001);

        Carbon::setTestNow();
    }

    public function test_omnibus_hidden_when_setting_disabled(): void
    {
        ShopSetting::put('pricing.sale_enabled', true);
        ShopSetting::put('commerce.omnibus_enabled', false);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'sale_price' => 50.00,
            'sale_price_type' => 'fixed',
            'is_on_sale' => true,
        ]);

        $presented = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertNull($presented['omnibus_lowest_price']);
        $this->assertNull($presented['omnibus_window_days']);
    }
}
