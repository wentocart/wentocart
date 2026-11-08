<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebMcpTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_declares_webmcp_tools(): void
    {
        $this->seed();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('toolname="view_product"', $html);
        $this->assertStringContainsString('toolname="add_to_wishlist"', $html);
        $this->assertStringContainsString('toolname="view_compare_list"', $html);
        $this->assertStringContainsString('toolname="subscribe_to_newsletter"', $html);
        $this->assertStringContainsString('navigator.modelContext', $html);
    }

    public function test_webmcp_product_redirects_to_search_when_missing(): void
    {
        $this->seed();

        $response = $this->get('/webmcp/product?query=definitely-no-such-sku-xyz');
        $response->assertRedirect();
        $this->assertStringContainsString('/search', $response->headers->get('Location') ?? '');
    }

    public function test_webmcp_product_resolves_by_sku(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->whereNotNull('sku')->first();
        $this->assertNotNull($product);

        $response = $this->get('/webmcp/product?query='.rawurlencode((string) $product->sku));
        $response->assertRedirect();
        $this->assertNotSame('', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('/search?', (string) $response->headers->get('Location'));
    }

    public function test_webmcp_wishlist_requires_login(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $location = (string) $this->get('/webmcp/wishlist/add?query='.rawurlencode((string) $product->sku))
            ->assertRedirect()
            ->headers->get('Location');
        $this->assertStringContainsString('/login', $location);
    }

    public function test_webmcp_wishlist_adds_for_customer(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->whereNotNull('sku')->first();
        $this->assertNotNull($product);

        $this->actingAs($customer, 'customer')
            ->get('/webmcp/wishlist/add?query='.rawurlencode((string) $product->sku))
            ->assertRedirect();

        $this->assertTrue($customer->fresh()->wishlist()->where('product_id', $product->id)->exists());
    }

    public function test_webmcp_newsletter_subscribes(): void
    {
        $this->seed();
        $email = 'webmcp-'.uniqid().'@example.test';

        $this->post('/webmcp/newsletter', ['email' => $email])
            ->assertRedirect();

        $this->assertTrue(NewsletterSubscriber::query()->where('email', $email)->exists());
    }
}
