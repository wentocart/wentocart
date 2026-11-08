<?php

namespace App\Console\Commands;

use App\Domain\Seo\SeoFixLive;
use Illuminate\Console\Command;

class SeoFixCommand extends Command
{
    protected $signature = 'wento:seo-fix {run : Background run id}';

    protected $description = 'Apply queued SEO / store-health automatic fixes without blocking admin HTTP.';

    public function handle(SeoFixLive $live): int
    {
        $run = trim((string) $this->argument('run'));
        if ($run === '') {
            $this->error('Missing run id.');

            return self::FAILURE;
        }

        $live->execute($run);
        $this->info('SEO fix finished.');

        return self::SUCCESS;
    }
}
