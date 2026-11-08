<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ops\Models\WebhookEndpoint;
use App\Domain\Ops\Models\WebserviceKey;
use App\Domain\Ops\WebhookSigner;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebserviceAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_webhook_signature_rejects_tampered_body_and_stale_timestamp(): void
    {
        $secret = WebhookEndpoint::generateSecret();
        $body = '{"ok":true}';
        $header = WebhookSigner::header($secret, $body, 1_700_000_000);
        $this->assertTrue(WebhookSigner::verify($secret, $header, $body, 300, 1_700_000_000));
        $this->assertFalse(WebhookSigner::verify($secret, $header, '{"ok":false}', 300, 1_700_000_000));
        $this->assertFalse(WebhookSigner::verify($secret, $header, $body, 300, 1_700_000_000 + 301));
        $this->assertFalse(WebhookSigner::verify($secret, 't=1,v1=not-a-hash', $body, 300, 1));
    }

    public function test_product_update_delivers_a_signed_https_webhook(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/ops-tools/webhooks', [
            'name' => 'ERP',
            'url' => 'https://hooks.example.test/shop',
            'events' => ['product.updated', 'product.created'],
        ])->assertRedirect();

        $product = $this->makeProduct('WS-HOOK-1', 8);
        $product->update(['price' => 9.5]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://hooks.example.test/shop') {
                return false;
            }
            $event = collect((array) $request->header('X-Wento-Event'))->first();
            if ($event !== 'product.updated') {
                return false;
            }
            $header = (string) collect((array) $request->header('X-Wento-Signature'))->first();
            $endpoint = WebhookEndpoint::query()->first();
            $this->assertTrue(WebhookSigner::verify((string) $endpoint->plainSecret(), $header, $request->body()));
            $json = json_decode((string) $request->body(), true);
            $this->assertSame('product.updated', $json['event'] ?? null);
            $this->assertSame('WS-HOOK-1', $json['data']['sku'] ?? null);
            $this->assertArrayNotHasKey('cost_price', $json['data'] ?? []);
            $this->assertArrayNotHasKey('password', $json['data'] ?? []);

            return true;
        });
    }

    public function test_http_and_private_webhook_urls_are_rejected(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->from('/admin/ops-tools')->post('/admin/ops-tools/webhooks', [
            'name' => 'Bad',
            'url' => 'https://127.0.0.1/hook',
            'events' => ['order.placed'],
        ])->assertSessionHasErrors('url');
    }

    public function test_webservice_rejects_query_string_keys_and_requires_scopes(): void
    {
        $issued = $this->issueKey(['shop.read']);
        $this->getJson('/api/v1/ws/shop?ws_key='.$issued['plain'])->assertStatus(400);
        $this->getJson('/api/v1/ws/shop')->assertStatus(401);
        $this->withToken($issued['plain'])->getJson('/api/v1/ws/products')->assertStatus(403);
        $this->withToken($issued['plain'])->getJson('/api/v1/ws/shop')->assertOk();
    }

    public function test_webservice_reads_and_writes_through_scoped_keys(): void
    {
        $read = $this->issueKey(['catalog.read', 'orders.read', 'customers.read']);
        $write = $this->issueKey(['catalog.read', 'catalog.write', 'customers.read', 'customers.write']);

        $this->withToken($read['plain'])->getJson('/api/v1/ws/products')->assertOk();
        $this->withToken($read['plain'])->postJson('/api/v1/ws/products', [
            'sku' => 'WS-NEW-1',
            'name' => 'Connector Lamp',
            'price' => 12,
            'stock_qty' => 3,
        ])->assertStatus(403);

        $this->withToken($write['plain'])->postJson('/api/v1/ws/products', [
            'sku' => 'WS-NEW-1',
            'name' => 'Connector Lamp',
            'price' => 12,
            'stock_qty' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'WS-NEW-1');

        $this->assertDatabaseHas('products', ['sku' => 'WS-NEW-1']);

        $created = $this->withToken($write['plain'])->postJson('/api/v1/ws/customers', [
            'email' => 'connector@example.test',
            'name' => 'Connector Buyer',
        ])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('password', $created);
        $this->assertArrayNotHasKey('totp_secret', $created);

        $id = (int) Product::query()->where('sku', 'WS-NEW-1')->value('id');
        $this->withToken($write['plain'])->deleteJson('/api/v1/ws/products/'.$id)->assertOk();
        $this->assertDatabaseMissing('products', ['sku' => 'WS-NEW-1']);

        $customer = Customer::query()->where('email', 'connector@example.test')->first();
        $this->assertNotNull($customer);
        $this->assertNotSame('', (string) $customer->password);
        $shown = $this->withToken($read['plain'])->getJson('/api/v1/ws/customers/'.$customer->id)
            ->assertOk()
            ->json('data');
        $this->assertArrayNotHasKey('password', $shown);
    }

    public function test_ip_allowlist_blocks_other_addresses(): void
    {
        $issued = $this->issueKey(['shop.read'], ['1.2.3.4']);
        $this->withToken($issued['plain'])->getJson('/api/v1/ws/shop')->assertStatus(403);
    }

    public function test_disabled_key_cannot_call_the_api(): void
    {
        $issued = $this->issueKey(['shop.read']);
        $issued['model']->update(['is_enabled' => false]);
        $this->withToken($issued['plain'])->getJson('/api/v1/ws/shop')->assertStatus(401);
    }

    public function test_invalid_bearer_tokens_are_rate_limited(): void
    {
        $fake = 'wntk_'.str_repeat('ab', 32);
        for ($i = 0; $i < 20; $i++) {
            $this->withToken($fake)->getJson('/api/v1/ws/shop')->assertStatus(401);
        }
        $this->withToken($fake)->getJson('/api/v1/ws/shop')->assertStatus(429);
    }

    public function test_webhook_manage_key_lists_endpoints_without_secrets(): void
    {
        Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/ops-tools/webhooks', [
            'name' => 'ERP',
            'url' => 'https://hooks.example.test/shop',
            'events' => ['order.placed'],
        ])->assertRedirect();

        $issued = $this->issueKey(['webhooks.manage']);
        $json = $this->withToken($issued['plain'])->getJson('/api/v1/ws/webhooks')->assertOk()->json('data.0');
        $this->assertSame('ERP', $json['name'] ?? null);
        $this->assertArrayNotHasKey('secret', $json);
        $this->assertArrayNotHasKey('secret_ciphertext', $json);
    }

    public function test_disabled_webhook_can_be_reenabled(): void
    {
        Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/ops-tools/webhooks', [
            'name' => 'ERP',
            'url' => 'https://hooks.example.test/shop',
            'events' => ['order.placed'],
        ])->assertRedirect();
        $id = WebhookEndpoint::query()->value('id');
        $this->post('/admin/ops-tools/webhooks/'.$id.'/toggle')->assertRedirect();
        $this->assertFalse((bool) WebhookEndpoint::query()->find($id)?->is_enabled);
        $this->post('/admin/ops-tools/webhooks/'.$id.'/toggle')->assertRedirect();
        $this->assertTrue((bool) WebhookEndpoint::query()->find($id)?->is_enabled);
    }

    public function test_prebuilt_catalog_is_discoverable_and_include_is_opt_in(): void
    {
        $shop = $this->issueKey(['shop.read']);
        $catalog = $this->issueKey(['catalog.read', 'customers.read']);

        $index = $this->withToken($shop['plain'])->getJson('/api/v1/ws')->assertOk()->json();
        $this->assertNotEmpty($index['endpoints'] ?? []);
        $this->assertFalse($index['auth']['query_string'] ?? true);

        $this->withToken($shop['plain'])->getJson('/api/v1/ws/languages')->assertOk();
        $this->withToken($shop['plain'])->getJson('/api/v1/ws/categories')->assertStatus(403);

        $product = $this->makeProduct('WS-INC-1', 11);
        $product->update(['cost_price' => 3]);
        $shown = $this->withToken($catalog['plain'])->getJson('/api/v1/ws/products/'.$product->id.'?include=images,categories,brand')
            ->assertOk()
            ->json('data');
        $this->assertSame('WS-INC-1', $shown['sku'] ?? null);
        $this->assertArrayHasKey('images', $shown);
        $this->assertArrayHasKey('categories', $shown);
        $this->assertArrayNotHasKey('cost_price', $shown);

        $methods = $this->withToken($shop['plain'])->getJson('/api/v1/ws/shipping-methods')->assertOk()->json('data');
        $this->assertIsArray($methods);
        if ($methods !== []) {
            $this->assertArrayNotHasKey('config', $methods[0]);
            $this->assertArrayNotHasKey('driver', $methods[0]);
        }

        $this->withToken($catalog['plain'])->getJson('/api/v1/ws/stock')->assertOk()
            ->assertJsonPath('meta.page', 1);
        $this->withToken($catalog['plain'])->getJson('/api/v1/ws/categories')->assertOk();
        $this->withToken($catalog['plain'])->getJson('/api/v1/ws/customer-groups')->assertOk();
    }

    public function test_admin_can_try_a_get_webservice_from_ops_tools(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $issued = $this->issueKey(['shop.read']);

        $this->from('/admin/ops-tools/webservices')->post('/admin/ops-tools/try', [
            'path' => '/api/v1/ws',
            'token' => $issued['plain'],
        ])->assertRedirect()->assertSessionHas('ops.ws.probe', function ($probe) {
            return is_array($probe)
                && ($probe['ok'] ?? false) === true
                && (int) ($probe['status'] ?? 0) === 200;
        });
    }

    public function test_admin_try_request_substitutes_path_id(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $issued = $this->issueKey(['catalog.read']);
        $product = $this->makeProduct('WS-TRY-ID', 4);

        $this->from('/admin/ops-tools/webservices')->post('/admin/ops-tools/try', [
            'path' => '/api/v1/ws/products/{id}',
            'path_id' => (string) $product->id,
            'token' => $issued['plain'],
            'include' => 'images',
        ])->assertRedirect()->assertSessionHas('ops.ws.probe', function ($probe) use ($product) {
            return is_array($probe)
                && ($probe['ok'] ?? false) === true
                && str_contains((string) ($probe['url'] ?? ''), '/products/'.$product->id)
                && ! str_contains((string) ($probe['url'] ?? ''), '{id}');
        });
    }

    public function test_admin_webservice_create_and_catalog_pages_load(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->get('/admin/ops-tools/keys/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/KeyCreate', false)->has('scopes'));
        $this->get('/admin/ops-tools/webservices')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/Webservices', false)->has('catalog.endpoints'));
    }

    public function test_creating_a_key_lands_on_the_web_services_list(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/ops-tools/keys', [
            'name' => 'Partner',
            'scopes' => ['shop.read'],
        ])->assertRedirect(route('admin.ops-tools.index', ['tab' => 'keys']))
            ->assertSessionHas('ops.api_key');
        $this->assertDatabaseHas('webservice_keys', ['name' => 'Partner']);
    }

    public function test_disabled_event_is_not_delivered(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/ops-tools/webhooks', [
            'name' => 'ERP',
            'url' => 'https://hooks.example.test/shop',
            'events' => ['product.updated'],
        ])->assertRedirect();
        $this->post('/admin/ops-tools/events/toggle', ['event' => 'product.updated'])->assertRedirect();

        $product = $this->makeProduct('WS-HOOK-OFF', 8);
        $product->update(['price' => 9.5]);

        Http::assertNothingSent();
    }

    public function test_category_created_delivers_a_signed_webhook(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/ops-tools/webhooks', [
            'name' => 'ERP',
            'url' => 'https://hooks.example.test/shop',
            'events' => ['category.created'],
        ])->assertRedirect();

        \App\Domain\Catalog\Models\Category::query()->create(['is_enabled' => true, 'position' => 0]);

        Http::assertSent(function ($request) {
            $event = collect((array) $request->header('X-Wento-Event'))->first();

            return $event === 'category.created';
        });
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $ips
     * @return array{model: WebserviceKey, plain: string}
     */
    protected function issueKey(array $scopes, array $ips = []): array
    {
        $plain = WebserviceKey::generatePlain();

        return WebserviceKey::issue([
            'name' => 'Test key',
            'scopes' => $scopes,
            'allowed_ips' => $ips,
            'is_enabled' => true,
        ], $plain);
    }

    protected function makeProduct(string $sku, float $price): Product
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => $sku,
            'price' => $price,
            'stock_qty' => 5,
            'is_enabled' => true,
            'visibility' => 'catalog_search',
            'available_for_order' => true,
            'show_price' => true,
            'manage_stock' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => $sku,
            'slug' => strtolower($sku),
        ]);

        return $product;
    }
}
