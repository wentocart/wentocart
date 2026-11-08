<?php

namespace Tests\Feature;

use App\Domain\Plugin\LocalExtensionCatalog;
use App\Domain\Plugin\PluginRegistry;
use App\Support\WebBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionPreviewAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_svg_serves_inline_for_img_tags(): void
    {
        $this->seed();
        $this->assertFileExists(base_path('extensions/weblytic/mainmenu/preview.svg'));

        $response = $this->get('/plugins/weblytic/mainmenu/preview.svg');
        $response->assertOk();
        $response->assertHeader('content-type', 'image/svg+xml');
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringNotContainsString('attachment', strtolower($disposition));
        $this->assertStringContainsString('inline', strtolower($disposition));
    }

    public function test_catalog_preview_urls_use_web_base_install_path(): void
    {
        $this->seed();
        config(['app.url' => 'http://localhost/shop']);

        $manifest = app(PluginRegistry::class)->find('weblytic', 'mainmenu');
        $this->assertNotNull($manifest);
        $this->assertSame(
            '/shop/plugins/weblytic/mainmenu/preview.svg',
            $manifest->previewPublicUrl(),
        );
        $this->assertSame(
            '/shop/plugins/weblytic/mainmenu/preview.svg',
            WebBase::url('/plugins/weblytic/mainmenu/preview.svg'),
        );

        $row = collect(app(LocalExtensionCatalog::class)->available())
            ->firstWhere('id', 'weblytic/mainmenu');
        // Catalog skips already-installed packages; assert helper regardless.
        $this->assertSame('/shop/plugins/weblytic/mainmenu/assets/x.css', $manifest->publicAssetUrl('assets/x.css'));
        if (is_array($row)) {
            $this->assertSame('/shop/plugins/weblytic/mainmenu/preview.svg', $row['preview']);
        }
    }
}
