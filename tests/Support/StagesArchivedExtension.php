<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Temporarily copy an archived package into extensions/ for feature tests.
 */
trait StagesArchivedExtension
{
    /** @var list<string> */
    protected array $stagedExtensionDirs = [];

    protected function stageArchivedExtension(string $vendor, string $name): string
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);
        // Archived samples live on branch archive/unused-extensions (not on main).
        $src = base_path('unused-extensions/'.$vendor.'/'.$name);
        $dest = base_path('extensions/'.$vendor.'/'.$name);
        if (! is_dir($src)) {
            $this->markTestSkipped(
                'Archived extension '.$vendor.'/'.$name.' is not on this tree. Checkout branch archive/unused-extensions to stage it.'
            );
        }
        if (is_dir($dest)) {
            return $dest;
        }
        File::ensureDirectoryExists(dirname($dest));
        File::copyDirectory($src, $dest);
        $this->stagedExtensionDirs[] = $dest;

        return $dest;
    }

    protected function cleanupStagedExtensions(): void
    {
        foreach (array_reverse($this->stagedExtensionDirs) as $dir) {
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
            $vendorDir = dirname($dir);
            if (is_dir($vendorDir) && File::directories($vendorDir) === [] && File::files($vendorDir) === []) {
                File::deleteDirectory($vendorDir);
            }
        }
        $this->stagedExtensionDirs = [];
    }
}
