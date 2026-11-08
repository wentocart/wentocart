<?php

namespace App\Console\Commands;

use App\Domain\Plugin\ExtensionSlug;
use App\Domain\Plugin\PluginRegistry;
use App\Domain\Plugin\SecurityScanner;
use App\Domain\Plugin\StructureValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class PackAppCommand extends Command
{
    protected $signature = 'wento:app-pack {id : vendor/name, for example demo/product-enquiry}';

    protected $description = 'Zip a Safe extension for Design → Extensions upload.';

    public function handle(PluginRegistry $registry, StructureValidator $structure, SecurityScanner $scanner): int
    {
        $id = strtolower(trim((string) $this->argument('id')));
        if (! ExtensionSlug::isValidId($id)) {
            $this->error('Use vendor/name letter/hyphen slugs, for example demo/product-enquiry.');

            return self::FAILURE;
        }
        [$vendor, $name] = explode('/', $id, 2);

        $manifest = null;
        foreach ([
            $registry->pathFor($vendor, $name),
            base_path('tests/Fixtures/extensions/'.$vendor.'/'.$name),
        ] as $src) {
            if (is_dir($src) && is_file($src.DIRECTORY_SEPARATOR.'app.json')) {
                $manifest = $registry->read($src, false);
                break;
            }
        }

        if (! $manifest) {
            $this->error($id.' was not found under extensions/ or tests/Fixtures/extensions/.');

            return self::FAILURE;
        }

        try {
            $structure->assertStandardTree($manifest->path, $manifest);
            $scanner->assertSafeTree($manifest->path, $manifest);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first() ?: 'Extension failed validation.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(base_path('plugin-packs'));
        $zipPath = base_path('plugin-packs/'.$vendor.'-'.$name.'.zip');
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->error('Could not write '.$zipPath);

            return self::FAILURE;
        }
        foreach (File::allFiles($manifest->path) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            if (str_ends_with($rel, '.gitkeep')) {
                continue;
            }
            $zip->addFile($file->getPathname(), $rel);
        }
        $zip->close();

        $this->info('Wrote '.$zipPath.' ('.number_format(filesize($zipPath) / 1024, 1).' KB).');
        $this->info('Upload it from Design → Extensions, then Enable — or Install if already under extensions/.');

        return self::SUCCESS;
    }
}
