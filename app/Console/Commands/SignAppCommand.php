<?php

namespace App\Console\Commands;

use App\Domain\Plugin\ExtensionSlug;
use App\Domain\Plugin\PluginRegistry;
use App\Domain\Plugin\PluginSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SignAppCommand extends Command
{
    protected $signature = 'wento:app-sign {id : vendor/name, for example community/fedex}';

    protected $description = 'HMAC-sign an extension app.json with APP_KEY so this shop can install certified zips (including schema.tables).';

    public function handle(PluginRegistry $registry): int
    {
        $id = strtolower(trim((string) $this->argument('id')));
        if (! ExtensionSlug::isValidId($id)) {
            $this->error('Use vendor/name letter/hyphen slugs, for example community/fedex.');

            return self::FAILURE;
        }
        [$vendor, $name] = explode('/', $id, 2);

        $src = null;
        foreach ([
            $registry->pathFor($vendor, $name),
            base_path('tests/Fixtures/extensions/'.$vendor.'/'.$name),
        ] as $dir) {
            if (is_dir($dir) && is_file($dir.DIRECTORY_SEPARATOR.'app.json')) {
                $src = $dir;
                break;
            }
        }
        if (! $src) {
            $this->error($id.' was not found under extensions/ or tests/Fixtures/extensions/.');

            return self::FAILURE;
        }

        $path = $src.DIRECTORY_SEPARATOR.'app.json';
        $raw = json_decode(File::get($path), true);
        if (! is_array($raw)) {
            $this->error('app.json is not valid JSON.');

            return self::FAILURE;
        }
        unset($raw['signature']);
        $manifest = $registry->read($src, false);
        $raw['signature'] = PluginSigner::sign($manifest);
        File::put($path, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info('Signed '.$id.' (hmac-sha256 with APP_KEY). Pack with php artisan wento:app-pack '.$id);

        return self::SUCCESS;
    }
}
