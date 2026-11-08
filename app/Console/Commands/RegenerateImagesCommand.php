<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\ImageOptimizer;
use Illuminate\Console\Command;

class RegenerateImagesCommand extends Command
{
    protected $signature = 'wento:images-regenerate {--missing : Only write types that are not on disk yet}';

    protected $description = 'Generate sized WebP (or JPEG) product image derivatives.';

    public function handle(ImageOptimizer $images): int
    {
        $result = $images->regenerate((bool) $this->option('missing'));
        $this->info('Processed '.$result['processed'].' images, wrote '.$result['files'].' files.');

        return self::SUCCESS;
    }
}
