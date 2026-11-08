<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WrapperHrefTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/package/checks.php';
    }

    public function test_unpack_redirect_stays_in_a_subfolder(): void
    {
        $this->assertSame('/shop/_extract.php', wrapper_href('_extract.php', '/shop/index.php'));
        $this->assertSame('/shop/setup/index.php?start=1', wrapper_href('setup/index.php?start=1', '/shop/_extract.php'));
        $this->assertSame('/_extract.php', wrapper_href('_extract.php', '/index.php'));
    }
}
