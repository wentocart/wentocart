<?php

namespace App\Console\Commands;

use App\Domain\Localization\UiI18n;
use Illuminate\Console\Command;

class SyncUiI18nCommand extends Command
{
    protected $signature = 'wento:i18n-sync {--force : Rescan source files even if the cache looks current}';

    protected $description = 'Register UI translation keys from the catalog and t() calls in admin/storefront code.';

    public function handle(): int
    {
        $count = UiI18n::sync((bool) $this->option('force'));
        $this->info("UI phrases synced ({$count} new or updated).");

        return self::SUCCESS;
    }
}
