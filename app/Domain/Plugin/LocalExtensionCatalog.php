<?php

namespace App\Domain\Plugin;

use App\Domain\Plugin\Models\InstalledPlugin;
use Illuminate\Validation\ValidationException;

class LocalExtensionCatalog
{
    public function __construct(
        protected PluginRegistry $registry,
    ) {}

    /**
     * Extensions on disk under extensions/ that are not yet installed (DB row).
     *
     * @return list<array<string, mixed>>
     */
    public function available(): array
    {
        $installed = InstalledPlugin::query()
            ->get(['vendor', 'name'])
            ->map(fn (InstalledPlugin $row) => strtolower($row->vendor.'/'.$row->name))
            ->all();

        $out = [];
        foreach ($this->registry->discover() as $manifest) {
            $id = $manifest->id();
            $already = in_array($id, $installed, true);
            if ($already) {
                continue;
            }
            $mayInstall = CertifiedApps::mayInstall($manifest);
            $community = CertifiedApps::communityUnsignedAllowed($manifest);
            $signed = CertifiedApps::shopSignedAllowed($manifest);
            $installable = $manifest->type === 'safe' || $mayInstall;
            $blocked = null;
            if ($manifest->type === 'certified' && ! $installable) {
                $blocked = 'Sign with php artisan wento:app-sign '.$id.' (APP_KEY) or use a payment/shipping driver zip.';
            }
            $out[] = [
                'vendor' => $manifest->vendor,
                'name' => $manifest->name,
                'id' => $id,
                'display_name' => $manifest->displayName,
                'version' => $manifest->version,
                'author' => $manifest->author,
                'description' => $manifest->description,
                'type' => $manifest->type,
                'category' => $manifest->category,
                'installable' => $installable,
                'blocked' => $blocked,
                'can_delete' => true,
                'preview' => $manifest->previewPublicUrl(),
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $out;
    }

    public function sourcePath(string $vendor, string $name): string
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);
        if (! ExtensionSlug::isValid($vendor) || ! ExtensionSlug::isValid($name)) {
            throw ValidationException::withMessages([
                'app' => 'Use vendor/name letter/hyphen slugs.',
            ]);
        }

        $path = $this->registry->pathFor($vendor, $name);
        $realRoot = realpath($this->registry->root());
        $realPath = realpath($path);
        if (! $realRoot || ! $realPath || ! str_starts_with($realPath, $realRoot) || ! is_file($realPath.DIRECTORY_SEPARATOR.'app.json')) {
            throw ValidationException::withMessages([
                'app' => 'That extension is not in the catalog.',
            ]);
        }

        return $realPath;
    }
}
