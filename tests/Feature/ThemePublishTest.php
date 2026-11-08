<?php

namespace Tests\Feature;

use App\Domain\Theme\ThemeInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_incremental_publish_restores_missing_public_theme_files(): void
    {
        $installer = app(ThemeInstaller::class);
        $installer->publishPublic('classic', false);

        $source = base_path('themes/classic/assets/img/social/facebook.png');
        $public = public_path('themes/classic/assets/img/social/facebook.png');
        $this->assertFileExists($source, 'Footer icons must live in git under themes/classic.');
        $this->assertFileExists($public);

        unlink($public);
        $this->assertFileDoesNotExist($public);

        $copied = $installer->publishPublic('classic', false);
        $this->assertGreaterThan(0, $copied);
        $this->assertFileExists($public);
        $this->assertSame(filesize($source), filesize($public));
    }

    public function test_classic_publish_includes_full_and_compact_sidebar_logos(): void
    {
        $full = 'themes/classic/assets/merchant/ui/logo.png';
        $short = 'themes/classic/assets/merchant/ui/logo_short.png';

        $this->assertFileExists(base_path($full), 'Full logo must ship in themes/classic for fresh installs.');
        $this->assertFileExists(base_path($short), 'Compact sidebar logo must ship in themes/classic for fresh installs.');

        $installer = app(ThemeInstaller::class);
        $installer->publishPublic('classic', false);

        $publicShort = public_path($short);
        if (is_file($publicShort)) {
            unlink($publicShort);
        }
        $this->assertFileDoesNotExist($publicShort);

        $copied = $installer->publishPublic('classic', false);
        $this->assertGreaterThan(0, $copied);
        $this->assertFileExists(public_path($full));
        $this->assertFileExists($publicShort);
        $this->assertSame(filesize(base_path($short)), filesize($publicShort));
    }
}
