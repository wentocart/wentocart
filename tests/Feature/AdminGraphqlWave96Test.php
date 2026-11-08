<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave96Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_cart_aliases_and_create_login(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        Product::query()->whereKey($product->id)->update([
            'manage_stock' => true,
            'stock_qty' => max(20, (int) $product->stock_qty),
            'is_enabled' => true,
        ]);

        $tokenRes = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { createCartToken { cart_token } }',
        ]);
        $tokenRes->assertOk();
        $this->assertArrayNotHasKey('errors', $tokenRes->json() ?? [], json_encode($tokenRes->json()));
        $cartToken = (string) $tokenRes->json('data.createCartToken.cart_token');
        $this->assertNotSame('', $cartToken);

        $add = $this->withHeader('X-Cart-Token', $cartToken)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAddProductInCart(productId: $id, quantity: 2) {
                    item_count itemsList { id product_id qty }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $this->assertSame(200, $add->status(), 'add: '.json_encode($add->json()));
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertGreaterThanOrEqual(1, (int) $add->json('data.createAddProductInCart.item_count'));
        $itemId = (string) ($add->json('data.createAddProductInCart.itemsList.0.id') ?? '');
        $this->assertNotSame('', $itemId);

        $read = $this->withHeader('X-Cart-Token', $cartToken)->postJson('/api/v1/graphql', [
            'query' => 'mutation { createReadCart { item_count grand_total } }',
        ]);
        $read->assertOk();
        $this->assertArrayNotHasKey('errors', $read->json() ?? [], json_encode($read->json()));
        $this->assertGreaterThanOrEqual(1, (int) $read->json('data.createReadCart.item_count'));

        $update = $this->withHeader('X-Cart-Token', $cartToken)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($iid: ID!) {
                createUpdateCartItem(cartItemId: $iid, quantity: 3) {
                    itemsList { id qty }
                }
            }',
            'variables' => ['iid' => $itemId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $qty = collect($update->json('data.createUpdateCartItem.itemsList') ?? [])
            ->firstWhere('id', $itemId)['qty'] ?? null;
        $this->assertEqualsWithDelta(3.0, (float) $qty, 0.001);

        $customer = Customer::factory()->create([
            'password' => Hash::make('password'),
            'is_enabled' => true,
        ]);

        $login = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomerLogin(email: "'.$customer->email.'", password: "password") {
                    success accessToken token token_type customer { id email }
                }
            }',
        ]);
        $this->assertSame(200, $login->status(), 'login: '.json_encode($login->json()));
        $this->assertArrayNotHasKey('errors', $login->json() ?? [], json_encode($login->json()));
        $this->assertTrue((bool) $login->json('data.createCustomerLogin.success'));
        $bearer = (string) $login->json('data.createCustomerLogin.accessToken');
        $this->assertNotSame('', $bearer);
        $this->assertSame($bearer, (string) $login->json('data.createCustomerLogin.token'));

        $merge = $this->withToken($bearer)
            ->withHeader('X-Cart-Token', $cartToken)
            ->postJson('/api/v1/graphql', [
                'query' => 'mutation ($t: String!) {
                    createMergeCart(cartToken: $t) { item_count }
                }',
                'variables' => ['t' => $cartToken],
            ]);
        $this->assertSame(200, $merge->status(), 'merge: '.json_encode($merge->json()));
        $this->assertArrayNotHasKey('errors', $merge->json() ?? [], json_encode($merge->json()));
        $this->assertGreaterThanOrEqual(1, (int) $merge->json('data.createMergeCart.item_count'));

        $remove = $this->withToken($bearer)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($iid: ID!) {
                createRemoveCartItem(cartItemId: $iid) { item_count }
            }',
            'variables' => ['iid' => $itemId],
        ]);
        $remove->assertOk();
        $this->assertArrayNotHasKey('errors', $remove->json() ?? [], json_encode($remove->json()));
    }
}
