<?php

namespace App\Console\Commands;

use App\Domain\Plugin\ExtensionSlug;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\StructureValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeAppCommand extends Command
{
    protected $signature = 'wento:make-app {id : vendor/name, for example acme/hello-banner} {--title= : Display name shown in Design → Extensions}';

    protected $description = 'Scaffold a Safe extension under extensions/{vendor}/{name}.';

    public function handle(StructureValidator $structure): int
    {
        $id = strtolower(trim((string) $this->argument('id')));
        if (! ExtensionSlug::isValidId($id)) {
            $this->error('Use vendor/name letter/hyphen slugs, for example acme/hello-banner (no digits).');

            return self::FAILURE;
        }
        [$vendor, $name] = explode('/', $id, 2);
        $dest = base_path('extensions/'.$vendor.'/'.$name);
        if (is_dir($dest)) {
            $this->error($id.' already exists at extensions/'.$vendor.'/'.$name);

            return self::FAILURE;
        }

        $title = trim((string) $this->option('title'));
        if ($title === '') {
            $title = ucwords(str_replace('-', ' ', $name));
        }
        $ns = 'Modules\\'.PluginManifest::studly($vendor).'\\'.PluginManifest::studly($name);

        File::ensureDirectoryExists($dest.'/src');
        File::put($dest.'/app.json', json_encode([
            'vendor' => $vendor,
            'name' => $name,
            'display_name' => $title,
            'version' => '1.0.0',
            'author' => $vendor,
            'type' => 'safe',
            'category' => 'other',
            'description' => $title.' — a Safe WentoCart extension. Edit this copy, then pack a zip.',
            'capabilities' => ['admin.page', 'settings', 'storefront.slot', 'hook.listen'],
            'hooks' => ['storefront.header.notice'],
            'setup_steps' => [
                'Upload and enable under Design → Extensions.',
                'Open the Configure screen and set your options.',
                'Confirm the storefront header notice (or change the slot in Plugin.php).',
            ],
            'preview' => 'preview.svg',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        File::put($dest.'/src/Plugin.php', $this->pluginPhp($ns, $title));
        File::put($dest.'/preview.svg', $this->previewSvg($title));
        File::put($dest.'/README.md', $this->readme($id, $title));

        $manifest = PluginManifest::fromArray(
            json_decode((string) File::get($dest.'/app.json'), true, 512, JSON_THROW_ON_ERROR),
            $dest,
            false
        );
        $structure->assertStandardTree($dest, $manifest);

        $this->info('Created extensions/'.$vendor.'/'.$name);
        $this->line('Handbook: docs/extensions/README.md');
        $this->line('Pack with: php artisan wento:app-pack '.$id);
        $this->line('Or Install from Design → Extensions (files are already on disk).');

        return self::SUCCESS;
    }

    protected function pluginPhp(string $ns, string $title): string
    {
        $titleExport = var_export($title, true);

        return <<<PHP
<?php

namespace {$ns};

use App\\Domain\\Plugin\\Support\\BasePlugin;

class Plugin extends BasePlugin
{
    public function boot(): void
    {
        \$this->hooks()->listen('storefront.header.notice', function (array \$payload) {
            if (! \$this->settings()->bool('enabled', true)) {
                return \$payload;
            }
            \$message = trim((string) \$this->settings()->get('message', {$titleExport}));
            if (\$message === '') {
                return \$payload;
            }
            \$existing = trim((string) (\$payload['notice'] ?? ''));
            \$payload['notice'] = \$existing === '' ? \$message : \$message.' — '.\$existing;

            return \$payload;
        });
    }

    public function adminSchema(): array
    {
        return [
            'title' => {$titleExport},
            'hint' => 'Core renders this form. The extension only declared the fields.',
            'fields' => [
                [
                    'name' => 'enabled',
                    'type' => 'toggle',
                    'label' => 'Show on the storefront header',
                ],
                [
                    'name' => 'message',
                    'type' => 'textarea',
                    'label' => 'Notice text',
                    'hint' => 'Shown in the storefront header slot.',
                ],
            ],
        ];
    }
}

PHP;
    }

    protected function previewSvg(string $title): string
    {
        $safe = htmlspecialchars(mb_substr($title, 0, 28), ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 400">
  <rect width="640" height="400" fill="#ede7ff"/>
  <rect x="48" y="160" width="544" height="80" rx="16" fill="#6d3aed"/>
  <text x="80" y="210" fill="#ffffff" font-size="28" font-family="Arial, sans-serif" font-weight="700">{$safe}</text>
</svg>
SVG;
    }

    protected function readme(string $id, string $title): string
    {
        return <<<MD
# {$title}

Safe WentoCart extension (`{$id}`).

## Setup

1. Edit `src/Plugin.php` and `app.json`. Stay on published hooks, lists, and slots.
2. For menus and hosted pages, see [docs/extensions/CODING-STANDARD.md](../../../docs/extensions/CODING-STANDARD.md).
3. Pack: `php artisan wento:app-pack {$id}`
4. Or Install from **Design → Extensions** (already under `extensions/`).

## Standard

- Handbook: [docs/extensions/README.md](../../../docs/extensions/README.md)
- Checklist: [docs/extensions/CHECKLIST.md](../../../docs/extensions/CHECKLIST.md)
- Bundled reference: `extensions/weblytic/mainmenu`. Fixtures: `tests/Fixtures/extensions/`
MD;
    }
}
