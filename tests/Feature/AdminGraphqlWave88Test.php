<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave88Test extends TestCase
{
    use RefreshDatabase;

    public function test_promotion_mass_ops_attribute_mass_delete_and_csv(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $cart = DiscountRule::query()->create([
            'name' => 'Wave88 Cart',
            'apply_as' => 'cart',
            'action' => 'percent',
            'value' => 10,
            'priority' => 100,
            'is_enabled' => true,
        ]);
        $catalog = DiscountRule::query()->create([
            'name' => 'Wave88 Catalog',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 5,
            'priority' => 100,
            'is_enabled' => true,
        ]);
        $coupon = DiscountRule::query()->create([
            'name' => 'Wave88 Coupon',
            'apply_as' => 'coupon',
            'code' => 'W88SAVE',
            'action' => 'percent',
            'value' => 15,
            'priority' => 100,
            'is_enabled' => true,
        ]);

        $promoCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/marketing/discount-rules/export');
        $promoCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $promoCsv->headers->get('content-type'));
        $this->assertStringContainsString('Wave88 Cart', $promoCsv->streamedContent());

        $createCart = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminMarketingCartDiscountRule(name: "W88 Cart Alias", value: 8) { id apply_as }
            }',
        ]);
        $createCart->assertOk();
        $this->assertArrayNotHasKey('errors', $createCart->json() ?? [], json_encode($createCart->json()));
        $this->assertSame('cart', $createCart->json('data.createAdminMarketingCartDiscountRule.apply_as'));

        $cartMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminMarketingCartDiscountRules(ids: [$id]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => ['id' => (string) $cart->id],
        ]);
        $cartMass->assertOk();
        $this->assertArrayNotHasKey('errors', $cartMass->json() ?? [], json_encode($cartMass->json()));
        $this->assertSame(1, (int) $cartMass->json('data.bulkDeleteAdminMarketingCartDiscountRules.updated_count'));
        $this->assertNull(DiscountRule::query()->find($cart->id));

        $catalogMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminMarketingCatalogDiscountRules(ids: [$id]) {
                    ok updated_count deleted
                }
            }',
            'variables' => ['id' => (string) $catalog->id],
        ]);
        $catalogMass->assertOk();
        $this->assertArrayNotHasKey('errors', $catalogMass->json() ?? [], json_encode($catalogMass->json()));
        $this->assertSame(1, (int) $catalogMass->json('data.bulkDeleteAdminMarketingCatalogDiscountRules.updated_count'));
        $this->assertNull(DiscountRule::query()->find($catalog->id));

        $couponMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminMarketingCartDiscountRuleCoupons(ids: [$id]) {
                    ok updated_count deleted
                }
            }',
            'variables' => ['id' => (string) $coupon->id],
        ]);
        $couponMass->assertOk();
        $this->assertArrayNotHasKey('errors', $couponMass->json() ?? [], json_encode($couponMass->json()));
        $this->assertSame(1, (int) $couponMass->json('data.bulkDeleteAdminMarketingCartDiscountRuleCoupons.updated_count'));
        $this->assertNull(DiscountRule::query()->find($coupon->id));

        $attr = Feature::query()->create([
            'code' => 'w88-attr',
            'type' => 'select',
            'is_filterable' => true,
            'used_for_combinations' => false,
            'is_enabled' => true,
            'position' => 990,
        ]);
        $attrMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminCatalogAttribute(ids: [$id]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => ['id' => (string) $attr->id],
        ]);
        $attrMass->assertOk();
        $this->assertArrayNotHasKey('errors', $attrMass->json() ?? [], json_encode($attrMass->json()));
        $this->assertSame(1, (int) $attrMass->json('data.bulkDeleteAdminCatalogAttribute.updated_count'));
        $this->assertNull(Feature::query()->find($attr->id));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/marketing/discount-rules/export')->assertUnauthorized();
    }
}
