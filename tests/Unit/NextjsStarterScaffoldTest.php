<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NextjsStarterScaffoldTest extends TestCase
{
    public function test_next_starter_covers_wishlist_reviews_and_auth_helper(): void
    {
        $root = dirname(__DIR__, 2).'/headless/nextjs-starter';
        if (! is_dir($root)) {
            $this->markTestSkipped('The Next.js starter is not part of this source tree.');
        }
        $this->assertFileExists($root.'/lib/wento.ts');
        $this->assertFileExists($root.'/app/wishlist/page.tsx');
        $this->assertFileExists($root.'/app/product/[slug]/page.tsx');
        $this->assertFileExists($root.'/app/checkout/page.tsx');

        $lib = file_get_contents($root.'/lib/wento.ts');
        $this->assertIsString($lib);
        $this->assertStringContainsString('graphqlAuthed', $lib);
        $this->assertStringContainsString('Authorization', $lib);

        $product = file_get_contents($root.'/app/product/[slug]/page.tsx');
        $this->assertIsString($product);
        $this->assertStringContainsString('productReviews', $product);

        $wish = file_get_contents($root.'/app/wishlist/page.tsx');
        $this->assertIsString($wish);
        $this->assertStringContainsString('wishlist', $wish);
    }
}
