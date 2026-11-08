<?php

namespace Tests\Unit;

use App\Domain\Installer\Models\ShopUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

class ShopUrlTest extends TestCase
{
    public function test_parses_folder_install_url(): void
    {
        $attrs = ShopUrl::attributesFromUrl('http://localhost/shop2');

        $this->assertSame('http', $attrs['scheme']);
        $this->assertSame('localhost', $attrs['domain']);
        $this->assertSame('/shop2/', $attrs['physical_uri']);
        $this->assertSame('/shop2/', ShopUrl::sessionPathFromUrl('http://localhost/shop2'));
        $this->assertSame('localhost', ShopUrl::hostFromUrl('http://localhost/shop2'));
    }

    public function test_extra_segment_stays_a_virtual_path_on_the_install_folder(): void
    {
        $aligned = ShopUrl::alignToInstall([
            'physical_uri' => '/shop/8/',
            'virtual_uri' => '',
        ], '/shop/');

        $this->assertSame('/shop/', $aligned['physical_uri']);
        $this->assertSame('8/', $aligned['virtual_uri']);

        $shop = new ShopUrl([
            'scheme' => 'http',
            'domain' => 'localhost',
            'port' => 8080,
            'physical_uri' => $aligned['physical_uri'],
            'virtual_uri' => $aligned['virtual_uri'],
        ]);

        $this->assertSame('http://localhost:8080/shop', $shop->installRoot());
        $this->assertSame('http://localhost:8080/shop/8', $shop->root());
    }

    public function test_virtual_uri_keeps_capitals_and_rejects_unsafe_paths(): void
    {
        $this->assertSame('SHOP/', ShopUrl::normalizeVirtual(' /SHOP/ '));
        $this->assertSame('Shop-2/', ShopUrl::normalizeVirtual('Shop-2'));
        $this->assertSame('tienda_mx/', ShopUrl::normalizeVirtual('tienda_mx'));
        $this->assertSame('商店/', ShopUrl::normalizeVirtual('商店'));
        $this->assertSame('Café/', ShopUrl::normalizeVirtual('Café'));

        $this->assertNull(ShopUrl::virtualUriMessage('SHOP'));
        $this->assertNull(ShopUrl::virtualUriMessage('Shop-2'));
        $this->assertNull(ShopUrl::virtualUriMessage('tienda_mx'));
        $this->assertNull(ShopUrl::virtualUriMessage('商店'));
        $this->assertNull(ShopUrl::virtualUriMessage(''));
        $this->assertNull(ShopUrl::virtualUriMessage('/'));

        $this->assertNotNull(ShopUrl::virtualUriMessage('Shop 2'));
        $this->assertNotNull(ShopUrl::virtualUriMessage('shop/2'));
        $this->assertNotNull(ShopUrl::virtualUriMessage('shop.php'));
        $this->assertNotNull(ShopUrl::virtualUriMessage('-shop'));
        $this->assertNotNull(ShopUrl::virtualUriMessage('admin'));
        $this->assertNotNull(ShopUrl::virtualUriMessage('ADMIN'));
        $this->assertSame('shop', ShopUrl::virtualKey('SHOP'));
        $this->assertSame('', ShopUrl::virtualPathRest('SHOP', 'shop'));
        $this->assertSame('cart', ShopUrl::virtualPathRest('Shop/cart', 'shop'));
        $this->assertNull(ShopUrl::virtualPathRest('shopping', 'shop'));
        $this->assertSame('SHOP/cart', ShopUrl::applyCanonicalVirtualCase('shop/cart', 'SHOP'));
    }

    public function test_virtual_uri_is_appended_to_root(): void
    {
        $shop = new ShopUrl(ShopUrl::attributesFromParts([
            'scheme' => 'http',
            'domain' => 'localhost',
            'port' => 8080,
            'physical_uri' => '/shop/',
            'virtual_uri' => 'web-shop-2',
        ]));

        $this->assertSame('web-shop-2/', $shop->virtual_uri);
        $this->assertSame('http://localhost:8080/shop/web-shop-2', $shop->root());
    }

    public function test_root_url_omits_default_port(): void
    {
        $shop = new ShopUrl(ShopUrl::attributesFromUrl('http://127.0.0.1:8000'));

        $this->assertSame('http://127.0.0.1:8000', $shop->root());
        $this->assertSame('/', ShopUrl::sessionPathFromUrl('http://127.0.0.1:8000'));
    }

    public function test_same_serving_origin_treats_loopback_aliases_as_equal(): void
    {
        $this->assertTrue(ShopUrl::sameServingOrigin('http://127.0.0.1:8099', 'http://localhost:8099/'));
        $this->assertTrue(ShopUrl::sameServingOrigin('http://127.0.0.1:8099', 'http://127.0.0.1:8099/'));
        $this->assertFalse(ShopUrl::sameServingOrigin('http://127.0.0.1:8099', 'http://127.0.0.1:8000'));
        $this->assertFalse(ShopUrl::sameServingOrigin('http://127.0.0.1:8099', 'https://127.0.0.1:8099'));
        $this->assertFalse(ShopUrl::sameServingOrigin('http://127.0.0.1:8099', 'http://127.0.0.1:8099/shop'));
        $this->assertFalse(ShopUrl::sameServingOrigin('http://127.0.0.1:8099', 'http://example.test:8099'));
    }

    public function test_folder_request_still_exposes_the_virtual_segment(): void
    {
        $request = Request::create('http://localhost:8000/shop/web-shop-2', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/shop/index.php',
            'SCRIPT_FILENAME' => '/var/www/shop/index.php',
        ]);

        $physical = ShopUrl::attributesFromRequest($request)['physical_uri'];

        $this->assertSame('/shop/', $physical);
        $this->assertContains('web-shop-2', ShopUrl::requestPathCandidates($request, $physical));
    }

    public function test_sanctum_domains_include_helper_port(): void
    {
        $domains = ShopUrl::sanctumDomainsFromUrl('http://127.0.0.1:8099');

        $this->assertContains('127.0.0.1:8099', $domains);
        $this->assertContains('localhost:8099', $domains);
    }
}
