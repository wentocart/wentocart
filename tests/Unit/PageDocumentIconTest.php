<?php

namespace Tests\Unit;

use App\Domain\Plugin\PageDocument;
use Tests\TestCase;

class PageDocumentIconTest extends TestCase
{
    public function test_link_blocks_keep_kit_emoji_fa_image_and_svg_icons(): void
    {
        $blocks = PageDocument::blocks([
            [
                'type' => 'link',
                'href' => '/m/acme/prime/home',
                'label' => 'Kit',
                'icon' => 'star',
            ],
            [
                'type' => 'link',
                'href' => '/m/acme/prime/home',
                'label' => 'Emoji',
                'icon' => '⭐',
            ],
            [
                'type' => 'link',
                'href' => '/m/acme/prime/home',
                'label' => 'FA',
                'icon' => 'fas fa-crown',
            ],
            [
                'type' => 'link',
                'href' => '/m/acme/prime/home',
                'label' => 'Image',
                'icon' => 'https://cdn.example.com/logo.png',
            ],
            [
                'type' => 'button',
                'label' => 'Ask',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2l2 6h6l-5 4 2 6-5-4-5 4 2-6-5-4h6z"/></svg>',
            ],
        ]);

        $this->assertSame(['kind' => 'kit', 'value' => 'star'], $blocks[0]['icon']);
        $this->assertSame('emoji', $blocks[1]['icon']['kind']);
        $this->assertSame('⭐', $blocks[1]['icon']['value']);
        $this->assertSame(['kind' => 'fa', 'value' => 'fas fa-crown'], $blocks[2]['icon']);
        $this->assertSame('image', $blocks[3]['icon']['kind']);
        $this->assertSame('https://cdn.example.com/logo.png', $blocks[3]['icon']['value']);
        $this->assertSame('svg', $blocks[4]['icon']['kind']);
        $this->assertStringContainsString('<svg', $blocks[4]['icon']['value']);
        $this->assertStringNotContainsString('script', strtolower($blocks[4]['icon']['value']));
    }

    public function test_unsafe_icon_payloads_are_rejected(): void
    {
        $this->assertNull(PageDocument::parseIcon('javascript:alert(1)'));
        $this->assertNull(PageDocument::parseIcon('fa-heart onclick=alert(1)'));
        $this->assertNull(PageDocument::parseIcon('<svg><script>alert(1)</script></svg>'));
        $this->assertNull(PageDocument::parseIcon('http://evil.test/x.png'));
        $this->assertSame('user-circle', PageDocument::iconName('user_circle'));
        $this->assertSame('grid', PageDocument::iconName('nope', 'grid'));
        $this->assertSame('settings', PageDocument::iconName('cog'));
        $this->assertSame('edit', PageDocument::iconName('fa-edit'));
        $this->assertSame('price-tag', PageDocument::iconName('price_tag'));
        $this->assertArrayHasKey('edit', PageDocument::iconCatalog());
        $this->assertArrayHasKey('price-tag', PageDocument::iconCatalog());
    }
}
