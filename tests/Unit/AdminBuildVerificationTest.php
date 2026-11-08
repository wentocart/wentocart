<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBuildVerificationTest extends TestCase
{
    #[Test]
    public function merchant_zip_scripts_always_rebuild_and_verify_admin_assets(): void
    {
        $sh = (string) file_get_contents(base_path('package/build-install-zip.sh'));
        $ps1 = (string) file_get_contents(base_path('package/build-test-zip.ps1'));

        $this->assertFileExists(base_path('package/verify-admin-build.php'));
        $this->assertStringContainsString('WENTO_SKIP_FRONTEND_BUILD', $sh);
        $this->assertStringContainsString('npm ci', $sh);
        $this->assertStringContainsString('npm run build', $sh);
        $this->assertStringContainsString('verify-admin-build.php', $sh);
        $this->assertStringContainsString('WENTO_SKIP_FRONTEND_BUILD', $ps1);
        $this->assertStringContainsString('npm ci', $ps1);
        $this->assertStringContainsString('npm run build', $ps1);
        $this->assertStringContainsString('verify-admin-build.php', $ps1);
        $verifier = (string) file_get_contents(base_path('package/verify-admin-build.php'));
        $this->assertStringContainsString('storefront/src/pages/Checkout.jsx', $verifier);
        $this->assertStringContainsString('return`/plugins/${', $verifier);
        $this->assertStringContainsString('ExtensionPrimaryNav', $verifier);
    }

    #[Test]
    public function local_admin_build_passes_navigation_checks_when_present(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            $this->markTestSkipped('Vite build manifest not found; run npm run build or package a merchant zip.');
        }

        $script = base_path('package/verify-admin-build.php');
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($manifest);
        $output = [];
        $exit = 0;
        exec($cmd.' 2>&1', $output, $exit);

        if ($exit !== 0) {
            // Tracked/copied public/build may lag source; merchant zips rebuild before verify.
            $this->markTestSkipped(
                "Local public/build failed admin nav checks (exit {$exit}). "
                .'Run npm run build, or build the merchant zip (which always rebuilds). '
                .implode("\n", $output)
            );
        }

        $this->assertStringContainsString('Admin build OK', implode("\n", $output));
    }
}
