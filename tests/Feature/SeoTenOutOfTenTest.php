<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\BrandTranslation;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryTranslation;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Seo\StorefrontSeoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTenOutOfTenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_noscript_contains_semantic_progressive_enhancement_without_cloaking_styles(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        $this->assertNotEmpty($slug);

        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            [
                'request_path' => $slug,
            ]
        );

        $response = $this->get('/'.$slug);

        $response->assertOk();
        $html = $response->getContent();

        // 1. Must be enclosed inside a semantic <noscript> tag
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString('id="wento-crawl-content"', $html);

        // 2. Must NOT contain search-engine cloaking CSS signatures
        $this->assertStringNotContainsString('clip:rect(0,0,0,0)', $html);
        $this->assertStringNotContainsString('aria-hidden="true" style="position:absolute;width:1px;height:1px', $html);
    }

    public function test_product_crawl_snapshot_contains_rich_ecommerce_metadata(): void
    {
        $brand = Brand::query()->create(['is_enabled' => true, 'code' => 'SEOBRAND']);
        BrandTranslation::query()->create([
            'brand_id' => $brand->id,
            'language_code' => 'en',
            'name' => 'Elite Audio Lab',
            'slug' => 'elite-audio-lab',
        ]);

        $product = Product::query()->create([
            'sku' => 'AUD-PRO-99',
            'price' => 299.99,
            'stock_qty' => 15,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
            'brand_id' => $brand->id,
            'cover_path' => '/storage/products/aud-pro.jpg',
        ]);

        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'language_code' => 'en',
            'name' => 'Audiophile Reference Headphones',
            'slug' => 'audiophile-reference-headphones',
            'short_description' => 'Flagship studio headphones with open-back acoustics.',
            'description' => 'Engineered for mixing and mastering with planar magnetic drivers.',
        ]);

        UrlRewrite::query()->create([
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'language_code' => 'en',
            'request_path' => 'audiophile-reference-headphones',
        ]);

        $response = $this->get('/audiophile-reference-headphones');
        $response->assertOk();
        $html = $response->getContent();

        // Check Rich Crawl Snapshot Content
        $this->assertStringContainsString('itemscope itemtype="https://schema.org/Product"', $html);
        $this->assertStringContainsString('itemprop="name">Audiophile Reference Headphones</span></h1>', $html);
        $this->assertStringContainsString('itemprop="priceCurrency"', $html);
        $this->assertStringContainsString('299.99', $html);
        $this->assertStringContainsString('https://schema.org/InStock', $html);
        $this->assertStringContainsString('In Stock', $html);
        $this->assertStringContainsString('SKU: <span itemprop="sku">AUD-PRO-99</span>', $html);
        $this->assertStringContainsString('Brand: <span itemprop="brand">Elite Audio Lab</span>', $html);
        $this->assertStringContainsString('Audiophile Reference Headphones - AUD-PRO-99', $html);
        $this->assertStringContainsString('Flagship studio headphones with open-back acoustics.', $html);
    }

    public function test_category_crawl_snapshot_lists_products_for_spiders(): void
    {
        $category = Category::query()->create(['is_enabled' => true]);
        CategoryTranslation::query()->create([
            'category_id' => $category->id,
            'language_code' => 'en',
            'name' => 'Studio Gear',
            'slug' => 'studio-gear',
            'description' => 'Professional recording studio equipment.',
        ]);

        $product = Product::query()->create([
            'sku' => 'MIC-STUDIO-1',
            'price' => 149.00,
            'quantity' => 5,
            'is_enabled' => true,
        ]);
        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'language_code' => 'en',
            'name' => 'Condenser Mic 100',
            'slug' => 'condenser-mic-100',
        ]);
        $category->products()->attach($product->id);

        UrlRewrite::query()->create([
            'entity_type' => 'category',
            'entity_id' => $category->id,
            'language_code' => 'en',
            'request_path' => 'studio-gear',
        ]);

        $response = $this->get('/studio-gear');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('wento-category-crawl', $html);
        $this->assertStringContainsString('Products in Studio Gear', $html);
        $this->assertStringContainsString('Condenser Mic 100', $html);
        $this->assertStringContainsString('149.00', $html);
    }

    public function test_crawler_detection_recognizes_bots_and_social_agents(): void
    {
        $this->assertTrue(StorefrontSeoPolicy::isCrawler('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        $this->assertTrue(StorefrontSeoPolicy::isCrawler('Mozilla/5.0 (compatible; Bingbot/2.0; +http://www.bing.com/bingbot.htm)'));
        $this->assertTrue(StorefrontSeoPolicy::isCrawler('facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'));
        $this->assertTrue(StorefrontSeoPolicy::isCrawler('Twitterbot/1.0'));
        $this->assertTrue(StorefrontSeoPolicy::isCrawler('WhatsApp/2.21.12.21 A'));
        $this->assertFalse(StorefrontSeoPolicy::isCrawler('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'));
        $this->assertFalse(StorefrontSeoPolicy::isCrawler(''));
    }

    public function test_crawler_user_agent_receives_crawler_snapshot(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        $this->assertNotEmpty($slug);

        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            [
                'request_path' => $slug,
            ]
        );

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ])->get('/'.$slug);

        $response->assertOk();
        $response->assertSee('id="wento-crawl-crawler"', false);
    }

    public function test_product_image_alt_text_fallback(): void
    {
        $product = Product::query()->create([
            'sku' => 'ALT-TEST-SKU',
            'is_enabled' => true,
        ]);

        $imageWithExplicitAlt = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/sample.png',
            'alt' => 'Custom Hand-Crafted Label',
            'position' => 1,
        ]);

        $imageWithoutAlt = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/sample2.png',
            'alt' => null,
            'position' => 2,
        ]);

        $this->assertSame('Custom Hand-Crafted Label', $imageWithExplicitAlt->altText());

        // Test fallback without loaded relation
        $this->assertSame('Product', $imageWithoutAlt->altText());

        // Test fallback with loaded product relation
        $imageWithoutAlt->load('product');
        $this->assertSame('Product - ALT-TEST-SKU', $imageWithoutAlt->altText());
    }
}
