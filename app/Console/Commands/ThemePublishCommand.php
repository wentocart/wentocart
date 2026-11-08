<?php

namespace App\Console\Commands;

use App\Domain\Theme\ThemeInstaller;
use Illuminate\Console\Command;

class ThemePublishCommand extends Command
{
    protected $signature = 'wento:theme-publish
                            {theme? : Theme folder name (default: all bundled themes)}
                            {--force : Delete public/themes/{name} and copy everything again}';

    protected $description = 'Copy themes/{name} into gitignored public/themes so the storefront can serve assets after clone or git pull';

    public function handle(ThemeInstaller $installer): int
    {
        $force = (bool) $this->option('force');
        $name = $this->argument('theme');
        $copied = is_string($name) && $name !== ''
            ? $installer->publishPublic($name, $force)
            : $installer->publishBundled($force);

        if ($copied === 0) {
            $this->line('Theme public copy is already up to date.');
        } else {
            $this->info('Copied '.$copied.' theme file'.($copied === 1 ? '' : 's').' into public/themes.');
        }

        return self::SUCCESS;
    }
}
