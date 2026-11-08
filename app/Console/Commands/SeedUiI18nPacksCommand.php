<?php

namespace App\Console\Commands;

use App\Domain\Localization\ShopLocaleDefaults;
use Illuminate\Console\Command;

class SeedUiI18nPacksCommand extends Command
{
    protected $signature = 'wento:i18n-seed-packs
                            {--force-sync : Rescan t() sources before seeding packs}
                            {--force : Overwrite existing bundled-locale rows from pack files (resets merchant edits for those locales)}';

    protected $description = 'Seed bundled UI chrome translations (fr, es, it, ar, hi) into the database.';

    public function handle(): int
    {
        if ($this->option('force-sync')) {
            $this->call('wento:i18n-sync', ['--force' => true]);
        }

        $missing = [];
        foreach (ShopLocaleDefaults::bundledUiLocales() as $locale) {
            $path = database_path('i18n/ui/'.$locale.'.json');
            if (! is_file($path)) {
                $missing[] = $locale;
            }
        }
        if ($missing !== []) {
            $this->warn('Locale pack files missing: '.implode(', ', $missing).' (database/i18n/ui/{locale}.json). Seeding whatever is available plus chromePack().');
        }

        ShopLocaleDefaults::seedLanguages();
        ShopLocaleDefaults::seedTranslations((bool) $this->option('force'));

        $this->info('Bundled UI translation packs seeded for: '.implode(', ', ShopLocaleDefaults::bundledUiLocales()).'.');

        return self::SUCCESS;
    }
}
