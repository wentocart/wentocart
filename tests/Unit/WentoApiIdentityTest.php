<?php

namespace Tests\Unit;

use App\Domain\Api\WentoApiEnvelope;
use App\Domain\Api\WentoApiIdentity;
use App\Domain\Settings\ConfigurationCatalog;
use PHPUnit\Framework\TestCase;

class WentoApiIdentityTest extends TestCase
{
    public function test_surfaces_and_appearance_vocabulary(): void
    {
        $this->assertSame('/api/v1/store/cart', WentoApiIdentity::storePath('cart'));
        $this->assertSame('/api/v1/console/products', WentoApiIdentity::consolePath('products'));
        $this->assertSame('hero', WentoApiIdentity::normalizeAppearanceType('hero'));
        $this->assertTrue(WentoApiIdentity::isAppearanceType('footer'));
        $this->assertTrue(WentoApiIdentity::isAppearanceType('featured'));
        // Foreign appearance type strings are rejected (not remapped).
        $this->assertFalse(WentoApiIdentity::isAppearanceType('image_carousel'));
        $this->assertFalse(WentoApiIdentity::isAppearanceType('product_carousel'));
    }

    public function test_configuration_catalog_uses_native_keys(): void
    {
        $slugs = ConfigurationCatalog::slugs();
        $this->assertSame(['shop', 'catalog', 'orders', 'seo', 'shipping'], $slugs);
        $this->assertSame('shop.name', ConfigurationCatalog::storageKey('shop.name'));
        $this->assertNotContains('general.content', $slugs);
        $this->assertNotContains('sales.order_settings', $slugs);
        $field = ConfigurationCatalog::fieldsFor('shop')[0];
        $this->assertArrayHasKey('storeBased', $field);
        $this->assertArrayNotHasKey('channelBased', $field);
    }

    public function test_envelope_snake_cases_wire_keys(): void
    {
        $payload = WentoApiEnvelope::snakeKeys([
            'themeCode' => 'classic',
            'sortOrder' => 2,
            'nested' => ['baseImageUrl' => 'x'],
            'currentPage' => 1,
        ]);

        $this->assertSame('classic', $payload['theme_code']);
        $this->assertSame(2, $payload['sort_order']);
        $this->assertSame('x', $payload['nested']['base_image_url']);
        $this->assertSame(1, $payload['current_page']);
    }
}
