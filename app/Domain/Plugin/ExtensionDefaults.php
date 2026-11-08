<?php

namespace App\Domain\Plugin;

use App\Domain\Plugin\Models\InstalledPlugin;

/**
 * Apply config('wento.apps.defaults') after a fresh shop install (Sam's first paint).
 */
class ExtensionDefaults
{
    public function __construct(
        protected PluginRegistry $registry,
        protected PluginInstaller $installer,
    ) {}

    public function apply(): void
    {
        $this->rehomeLegacy();

        $matrix = config('wento.apps.defaults', []);
        if (! is_array($matrix) || $matrix === []) {
            return;
        }

        foreach ($matrix as $id => $state) {
            if (! is_string($id) || ! ExtensionSlug::isValidId(strtolower($id))) {
                continue;
            }
            [$vendor, $name] = explode('/', strtolower($id), 2);
            if (! $this->registry->existsOnDisk($vendor, $name)) {
                continue;
            }
            if (InstalledPlugin::query()->where('vendor', $vendor)->where('name', $name)->exists()) {
                continue;
            }
            $manifest = $this->registry->find($vendor, $name);
            if (! $manifest) {
                continue;
            }
            $want = is_string($state) ? $state : (string) ($state['state'] ?? 'available');
            if ($want === 'available') {
                continue;
            }
            if (in_array($want, ['installed', 'disabled', 'enabled'], true)) {
                $this->installer->persist($manifest, false, $want === 'enabled');
            }
        }
    }

    /**
     * Keep plugin settings when a bundled id moves (vendor/name + folder).
     */
    public function rehomeLegacy(): void
    {
        $map = [
            'wento/main-menu' => 'weblytic/mainmenu',
        ];
        foreach ($map as $from => $to) {
            if (! is_string($from) || ! is_string($to) || ! str_contains($from, '/') || ! str_contains($to, '/')) {
                continue;
            }
            [$fromVendor, $fromName] = explode('/', strtolower($from), 2);
            [$toVendor, $toName] = explode('/', strtolower($to), 2);
            $old = InstalledPlugin::query()->where('vendor', $fromVendor)->where('name', $fromName)->first();
            if (! $old) {
                continue;
            }
            $exists = InstalledPlugin::query()->where('vendor', $toVendor)->where('name', $toName)->exists();
            if ($exists) {
                $old->delete();

                continue;
            }
            $old->vendor = $toVendor;
            $old->name = $toName;
            $old->path = $this->registry->pathFor($toVendor, $toName);
            $old->save();
        }
    }
}
