<?php

namespace App\Console\Commands;

use App\Domain\Catalog\MerchantStoreBuilder;
use Illuminate\Console\Command;

class MerchantStoreCommand extends Command
{
    protected $signature = 'wento:merchant-store
        {--source= : Copy images from this folder into themes/classic/assets/merchant before seeding}
        {--force : Skip confirmation}';

    protected $description = 'Wipe storefront catalog media and rebuild from bundled merchant assets (also used by demo install).';

    public function handle(MerchantStoreBuilder $builder): int
    {
        if (! $this->option('force') && ! $this->confirm('This deletes products, categories, brands, orders, customers, and storefront media. Continue?')) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        $source = trim((string) $this->option('source'));
        if ($source !== '') {
            $this->importSourceAssets($source);
        }

        $builder->run();

        $this->info('Merchant store rebuilt. Open the storefront home page and catalog to review.');

        return self::SUCCESS;
    }

    protected function importSourceAssets(string $source): void
    {
        if (! is_dir($source)) {
            $this->error("Source folder not found: {$source}");

            exit(self::FAILURE);
        }

        $root = base_path(MerchantStoreBuilder::ASSET_ROOT);
        foreach (['banners', 'promo', 'products', 'home', 'brands'] as $dir) {
            if (! is_dir($root.'/'.$dir)) {
                mkdir($root.'/'.$dir, 0755, true);
            }
        }

        $map = [
            $source.DIRECTORY_SEPARATOR.'image' => $root.DIRECTORY_SEPARATOR.'banners',
            $source.DIRECTORY_SEPARATOR.'image 2' => $root.DIRECTORY_SEPARATOR.'promo',
            $source.DIRECTORY_SEPARATOR.'image 3' => $root.DIRECTORY_SEPARATOR.'products',
        ];

        foreach ($map as $from => $to) {
            if (! is_dir($from)) {
                $this->warn("Skipping missing folder: {$from}");
                continue;
            }
            $this->copyTree($from, $to);
        }

        foreach ([1, 2, 3] as $n) {
            $src = $root.DIRECTORY_SEPARATOR.'banners'.DIRECTORY_SEPARATOR.$n.'.png';
            if (is_file($src)) {
                copy($src, $root.DIRECTORY_SEPARATOR.'home'.DIRECTORY_SEPARATOR.'hero-'.$n.'.png');
            }
        }
        foreach ([18 => 1, 19 => 2] as $banner => $side) {
            $src = $root.DIRECTORY_SEPARATOR.'banners'.DIRECTORY_SEPARATOR.$banner.'.png';
            if (is_file($src)) {
                copy($src, $root.DIRECTORY_SEPARATOR.'home'.DIRECTORY_SEPARATOR.'side-'.$side.'.png');
            }
        }

        $this->info('Imported merchant images from '.$source);
    }

    protected function copyTree(string $from, string $to): void
    {
        foreach (glob($from.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                copy($file, $to.DIRECTORY_SEPARATOR.basename($file));
            }
        }
    }
}
