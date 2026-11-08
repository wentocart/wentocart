<?php

namespace Tests\Unit;

use App\Support\WebBase;
use Tests\TestCase;

class WebBaseTest extends TestCase
{
    public function test_console_uses_app_url_path(): void
    {
        config(['app.url' => 'http://localhost:8000/shop']);

        $this->assertSame('/shop', WebBase::path());
        $this->assertSame('/shop/themes/classic/a.css', WebBase::url('themes/classic/a.css'));
        $this->assertSame('/shop/storage/covers/a.jpg', WebBase::url('/shop/shop/storage/covers/a.jpg'));
        $this->assertSame('/shop/themes/classic/a.css', WebBase::prefixPublicMedia('/themes/classic/a.css'));
        $this->assertSame('/catalog', WebBase::prefixPublicMedia('/catalog'));
    }

    public function test_classic_header_css_does_not_request_host_root_fonts(): void
    {
        $css = (string) file_get_contents(base_path('themes/classic/assets/header.css'));

        $this->assertStringNotContainsString('url(/themes/classic/assets/fonts/', $css);
        $this->assertStringNotContainsString('@font-face', $css);
    }

    public function test_host_root_app_url_has_no_prefix(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->assertSame('', WebBase::path());
        $this->assertSame('/themes/classic/a.css', WebBase::url('/themes/classic/a.css'));
    }
}
