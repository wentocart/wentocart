<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Store\Models\Store;
use App\Domain\Store\Models\StoreGroup;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_virtual_shops_do_not_share_a_customer_session(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/shop/multishop', ['enabled' => 1])
            ->assertRedirect();

        $group = StoreGroup::query()->create([
            'name' => 'Shared',
            'code' => 'shared',
            'share_customers' => true,
            'share_orders' => true,
            'share_stock' => true,
            'is_enabled' => true,
        ]);
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $mobile = $this->makeShop('Mobile', 'mobile', 'mobile@example.com', $source, $group);
        $electronic = $this->makeShop('Electronic', 'eletronic', 'electronic@example.com', $source, $group);

        $customer = Customer::factory()->create([
            'email' => 'demo@demo.test',
            'store_id' => $mobile->id,
        ]);

        $login = $this->postJson('http://localhost/mobile/api/v1/auth/login', [
            'email' => 'demo@demo.test',
            'password' => 'password',
        ]);
        $login->assertOk();
        $login->assertJsonPath('customer.email', 'demo@demo.test');

        $cookieName = (string) config('session.storefront_cookie').'-s'.$mobile->id;
        $cookie = $login->getCookie($cookieName);
        $this->assertNotNull($cookie);
        $this->assertSame('/mobile', $cookie->getPath());
        $clearingToken = null;
        foreach ($login->headers->getCookies() as $row) {
            if ($row->getName() === 'XSRF-TOKEN' && $row->getPath() === '/') {
                $clearingToken = $row;
            }
        }
        $this->assertNotNull($clearingToken);
        $this->assertFalse($clearingToken->isHttpOnly());

        $other = $this->get('http://localhost/eletronic');
        $otherCookie = $other->getCookie((string) config('session.storefront_cookie').'-s'.$electronic->id);
        $this->assertNotNull($otherCookie);
        $this->assertSame('/eletronic', $otherCookie->getPath());
        $this->assertNotSame($cookieName, $otherCookie->getName());

        $this->flushSession();
        auth()->forgetGuards();

        $this->withCredentials()
            ->withCookie($cookieName, $cookie->getValue())
            ->getJson('http://localhost/eletronic/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('customer', null);

        $this->flushSession();
        auth()->forgetGuards();

        $this->withCredentials()
            ->withCookie($cookieName, $cookie->getValue())
            ->getJson('http://localhost/mobile/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('customer.email', $customer->email);
    }

    public function test_shared_customer_can_save_an_address_on_the_other_shop(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/shop/multishop', ['enabled' => 1])
            ->assertRedirect();

        $group = StoreGroup::query()->create([
            'name' => 'Shared',
            'code' => 'shared-checkout',
            'share_customers' => true,
            'share_orders' => true,
            'share_stock' => true,
            'is_enabled' => true,
        ]);
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $mobile = $this->makeShop('Mobile', 'mobile', 'mobile@example.com', $source, $group);
        $electronic = $this->makeShop('Electronic', 'eletronic', 'electronic@example.com', $source, $group);

        Customer::factory()->create([
            'email' => 'demo@demo.test',
            'store_id' => $mobile->id,
        ]);

        $login = $this->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost/eletronic/login')
            ->postJson('http://localhost/eletronic/api/v1/auth/login', [
                'email' => 'demo@demo.test',
                'password' => 'password',
            ]);
        $login->assertOk();
        $login->assertJsonPath('customer.email', 'demo@demo.test');

        $cookieName = (string) config('session.storefront_cookie').'-s'.$electronic->id;
        $cookie = $login->getCookie($cookieName);
        $this->assertNotNull($cookie);

        $this->flushSession();
        auth()->forgetGuards();

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost/eletronic/checkout')
            ->withCookie($cookieName, $cookie->getValue())
            ->getJson('http://localhost/eletronic/api/v1/account')
            ->assertOk()
            ->assertJsonPath('customer.email', 'demo@demo.test');

        $this->flushSession();
        auth()->forgetGuards();

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost/eletronic/checkout')
            ->withCookie($cookieName, $cookie->getValue())
            ->postJson('http://localhost/eletronic/api/v1/account/addresses', [
                'first_name' => 'Demo',
                'last_name' => 'Demo',
                'line1' => '12 Analytical Engine Rd',
                'city' => 'London',
                'state' => 'England',
                'postal_code' => 'SW1A 1AA',
                'country_code' => 'GB',
                'is_default_shipping' => true,
                'is_default_billing' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('address.first_name', 'Demo');
    }

    protected function makeShop(string $name, string $virtual, string $email, Store $source, StoreGroup $group): Store
    {
        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/shops', [
                'name' => $name,
                'email' => $email,
                'scheme' => 'http',
                'domain' => 'localhost',
                'physical_uri' => '/',
                'virtual_uri' => $virtual,
                'theme' => 'classic',
                'preset' => 'empty',
                'copy_from_id' => $source->id,
                'group_id' => $group->id,
            ])
            ->assertRedirect();

        return Store::query()->where('name', $name)->firstOrFail();
    }
}
