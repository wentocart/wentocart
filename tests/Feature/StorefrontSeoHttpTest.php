<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Localization\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontSeoHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unknown_path_returns_404_with_noindex(): void
    {
        $this->get('/this-page-definitely-missing-xyz')
            ->assertStatus(404)
            ->assertSee('noindex', false)
            ->assertSee('Page not found', false);
    }

    public function test_cart_is_noindex(): void
    {
        $this->get('/cart')
            ->assertOk()
            ->assertSee('name="robots" content="noindex,nofollow"', false);
    }

    public function test_search_is_noindex(): void
    {
        $this->get('/search')
            ->assertOk()
            ->assertSee('noindex,nofollow', false);
    }

    public function test_category_with_filter_query_is_noindex(): void
    {
        $category = Category::query()->where('is_enabled', true)->first();
        $this->assertNotNull($category);
        $slug = $category->translation('en')?->slug;
        $this->assertNotEmpty($slug);

        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'category',
                'entity_id' => $category->id,
                'language_code' => 'en',
            ],
            [
                'request_path' => $slug,
            ]
        );

        $this->get('/'.$slug.'?brand=1')
            ->assertOk()
            ->assertSee('noindex,follow', false);
    }

    public function test_enabled_product_includes_crawl_html(): void
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

        $this->get('/'.$slug)
            ->assertOk()
            ->assertSee('wento-crawl-content', false)
            ->assertSee('<h1>', false);
    }

    public function test_hreflang_includes_x_default_when_multiple_languages(): void
    {
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2]
        );

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;

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
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'fr',
            ],
            [
                'request_path' => 'fr-'.$slug,
            ]
        );

        $this->get('/'.$slug)
            ->assertOk()
            ->assertSee('hreflang="x-default"', false);
    }

    public function test_product_variant_query_is_noindex(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );

        $this->get('/'.$slug.'?ipa=12')
            ->assertOk()
            ->assertSee('noindex,follow', false);
    }

    public function test_thin_catalog_shell_is_noindex(): void
    {
        $this->get('/blog')
            ->assertOk()
            ->assertSee('name="robots" content="noindex,follow"', false);
    }

    public function test_robots_txt_disallows_locale_prefixed_utilities(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /*/cart', false)
            ->assertSee('Disallow: /checkout', false);
    }

    public function test_disabled_product_returns_410(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );
        $product->update(['is_enabled' => false]);

        $this->get('/'.$slug)
            ->assertStatus(410)
            ->assertSee('noindex', false);
    }

    public function test_product_html_includes_product_json_ld_and_offer_currency(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );

        $this->get('/'.$slug)
            ->assertOk()
            ->assertSee('"@type":"Product"', false)
            ->assertSee('priceCurrency', false)
            ->assertSee('BreadcrumbList', false);
    }

    public function test_product_api_seo_includes_json_ld_and_og_image(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.seo.json_ld.@graph.0.@type', 'Product')
            ->assertJsonStructure(['product' => ['seo' => ['title', 'description', 'og_image', 'json_ld']]]);
    }

    public function test_path_locale_sitemap_pairs_language_code_not_cross_prefix(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.locale_url_mode', 'path');
        \App\Domain\Settings\ShopSetting::put('seo.sitemap_enabled', true);

        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2]
        );

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $enSlug = $product->translation('en')?->slug ?: 'en-product';
        $frSlug = 'fr-'.$enSlug;

        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $enSlug]
        );
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'fr',
            ],
            ['request_path' => $frSlug]
        );

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/en/'.$enSlug, $xml);
        $this->assertStringContainsString('/fr/'.$frSlug, $xml);
        $this->assertStringNotContainsString('/fr/'.$enSlug, $xml);
        $this->assertStringNotContainsString('/en/'.$frSlug, $xml);
        $this->assertStringContainsString('xhtml:link', $xml);
        $this->assertStringContainsString('hreflang="x-default"', $xml);
    }

    public function test_merchant_feed_includes_description_and_identifier_exists_when_needed(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.merchant_feed', true);
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $product->update([
            'gtin' => null,
            'upc' => null,
            'barcode' => null,
            'mpn' => null,
            'brand_id' => null,
        ]);

        $xml = $this->get('/feeds/google-merchant.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<g:description>', $xml);
        $this->assertStringContainsString('<g:identifier_exists>false</g:identifier_exists>', $xml);
    }

    public function test_path_locale_product_request_is_ok(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.locale_url_mode', 'path');
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );

        $this->get('/en/'.$slug)
            ->assertOk()
            ->assertSee('"@type":"Product"', false);
    }

    public function test_home_includes_website_search_action(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('SearchAction', false)
            ->assertSee('Organization', false);
    }

    public function test_cookie_home_omits_duplicate_hreflang(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.locale_url_mode', 'cookie');
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2]
        );

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('hreflang="fr"', $html);
    }

    public function test_path_mode_bare_product_path_redirects_to_locale_prefix(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.locale_url_mode', 'path');
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );

        $this->get('/'.$slug)->assertRedirect('/en/'.$slug);
    }

    public function test_product_api_includes_hreflang_and_absolute_canonical(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.locale_url_mode', 'path');
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2]
        );
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'fr',
            ],
            ['request_path' => 'fr-'.$slug]
        );

        $response = $this->getJson('/api/v1/catalog/product/'.$slug)->assertOk();
        $canonical = (string) $response->json('product.seo.canonical');
        $this->assertStringContainsString('/en/', $canonical);
        $this->assertNotEmpty($response->json('product.seo.hreflang'));
    }

    public function test_merchant_feed_includes_product_type_or_identifier_exists(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.merchant_feed', true);
        $xml = $this->get('/feeds/google-merchant.xml')->assertOk()->getContent();
        $this->assertTrue(
            str_contains($xml, '<g:product_type>') || str_contains($xml, '<g:identifier_exists>'),
            'Feed should emit product_type and/or identifier_exists'
        );
        $this->assertStringContainsString('<g:description>', $xml);
    }

    public function test_help_ssr_includes_faq_page_json_ld(): void
    {
        $this->get('/help')
            ->assertOk()
            ->assertSee('FAQPage', false)
            ->assertSee('acceptedAnswer', false);
    }

    public function test_product_with_review_emits_aggregate_rating(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );

        $customer = \App\Domain\Customer\Models\Customer::createTrusted([
            'name' => 'Seo Reviewer',
            'email' => 'seo-review-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
        ]);

        \App\Domain\Customer\Models\Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'title' => 'Great',
            'body' => 'Works well for the SEO audit.',
            'is_approved' => true,
            'status' => 'approved',
        ]);

        $this->get('/'.$slug)
            ->assertOk()
            ->assertSee('AggregateRating', false)
            ->assertSee('reviewCount', false);
    }

    public function test_category_facet_canonical_omits_query_string(): void
    {
        $category = Category::query()->where('is_enabled', true)->first();
        $this->assertNotNull($category);
        $slug = $category->translation('en')?->slug;
        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'category',
                'entity_id' => $category->id,
                'language_code' => 'en',
            ],
            ['request_path' => $slug]
        );

        $html = $this->get('/'.$slug.'?brand=1&page=2')->assertOk()->getContent();
        $this->assertStringContainsString('noindex,follow', $html);
        $this->assertMatchesRegularExpression(
            '#rel="canonical" href="[^"]*/'.preg_quote($slug, '#').'"#',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '#rel="canonical" href="[^"]*\?brand=#',
            $html
        );
    }

    public function test_merchant_feed_supports_page_and_hl_query(): void
    {
        \App\Domain\Settings\ShopSetting::put('seo.merchant_feed', true);
        $this->get('/feeds/google-merchant.xml?page=1&hl=en')
            ->assertOk()
            ->assertSee('<g:id>', false);
    }

    public function test_wrong_id_slug_redirects_to_canonical_product_url(): void
    {
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug;
        $this->assertNotEmpty($slug);
        $canonical = $product->id.'-'.$slug;

        UrlRewrite::query()->updateOrCreate(
            [
                'entity_type' => 'product',
                'entity_id' => $product->id,
                'language_code' => 'en',
            ],
            ['request_path' => $canonical]
        );

        $this->get('/'.$product->id.'-not-the-real-slug')
            ->assertStatus(301)
            ->assertRedirect('/'.$canonical);
    }

    public function test_missing_storefront_path_paints_404_before_javascript(): void
    {
        $this->get('/this-page-definitely-missing-xyz')
            ->assertStatus(404)
            ->assertSee('wento-boot-missing', false)
            ->assertSee('"not_found":true', false);
    }
}
