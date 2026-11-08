<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DirectoryIndexTest extends TestCase
{
    public function test_product_folders_have_deny_listing_index(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame([], $this->missing($root));
    }

    public function test_config_must_not_have_index_php(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'index.php');
        $this->assertFileExists($root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'index.html');
    }

    /**
     * @return list<string>
     */
    private function missing(string $root): array
    {
        $roots = ['app', 'bootstrap', 'database', 'docs', 'extensions', 'package', 'public', 'resources', 'routes', 'scripts', 'setup', 'storefront', 'tests', 'themes'];
        $skipExact = ['public/build', 'public/hot', 'public/storage', 'public/i18n', 'bootstrap/cache'];
        $missing = [];

        foreach ($roots as $top) {
            $dir = $root.DIRECTORY_SEPARATOR.$top;
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                if (! $item->isDir()) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                $skip = false;
                foreach ($skipExact as $name) {
                    if ($rel === $name || str_starts_with($rel, $name.'/')) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
                if (! is_file($item->getPathname().DIRECTORY_SEPARATOR.'index.php')) {
                    $missing[] = $rel;
                }
            }
            if (! is_file($dir.DIRECTORY_SEPARATOR.'index.php')) {
                $missing[] = $top;
            }
        }

        return array_values(array_unique($missing));
    }
}
