<?php

namespace App\Domain\Plugin;

use App\Domain\Plugin\Models\InstalledPlugin;
use Illuminate\Validation\ValidationException;

class DependencyResolver
{
    /**
     * @param  array<string, array{version: string, enabled: bool}>  $installed  id => meta
     * @return list<string>
     */
    public function validateForInstallOrUpdate(PluginManifest $manifest, array $installed = []): array
    {
        $installed = $installed !== [] ? $installed : $this->installedMap();
        $errors = [];

        foreach ($manifest->requires as $id => $constraint) {
            if (! isset($installed[$id])) {
                $errors[] = 'Missing dependency: '.$id.' ('.$constraint.').';

                continue;
            }
            if (! $this->satisfies($installed[$id]['version'], $constraint)) {
                $errors[] = 'Dependency version conflict: need '.$id.' '.$constraint.', found '.$installed[$id]['version'].'.';
            }
        }

        foreach ($manifest->conflicts as $id) {
            if (isset($installed[$id]) && $installed[$id]['enabled']) {
                $errors[] = 'Conflicts with enabled extension '.$id.'.';
            }
        }

        $cycle = $this->detectCycle($manifest, $installed);
        if ($cycle !== null) {
            $errors[] = 'Dependency cycle detected: '.$cycle.'.';
        }

        return $errors;
    }

    /**
     * Enabling requires dependencies to be installed and enabled.
     *
     * @param  array<string, array{version: string, enabled: bool}>  $installed
     * @return list<string>
     */
    public function validateForEnable(PluginManifest $manifest, array $installed = []): array
    {
        $installed = $installed !== [] ? $installed : $this->installedMap();
        $errors = $this->validateForInstallOrUpdate($manifest, $installed);

        foreach ($manifest->requires as $id => $constraint) {
            if (isset($installed[$id]) && ! $installed[$id]['enabled']) {
                $errors[] = 'Dependency is installed but disabled: '.$id.'. Enable it first.';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, array{version: string, enabled: bool}>  $installed
     * @return list<string>
     */
    public function validateForDisableOrUninstall(string $id, array $installed = []): array
    {
        $installed = $installed !== [] ? $installed : $this->installedMap();
        $errors = [];
        $id = strtolower($id);

        foreach ($installed as $otherId => $meta) {
            if (! $meta['enabled'] || $otherId === $id) {
                continue;
            }
            $manifest = $this->manifestFor($otherId);
            if ($manifest === null) {
                continue;
            }
            if (array_key_exists($id, $manifest->requires)) {
                $errors[] = 'Extension '.$otherId.' depends on '.$id.'. Disable or uninstall it first.';
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function assertInstallable(PluginManifest $manifest): array
    {
        $errors = $this->validateForInstallOrUpdate($manifest);
        if ($errors !== []) {
            throw ValidationException::withMessages(['zip' => $errors[0], 'dependencies' => $errors]);
        }

        return $errors;
    }

    public function assertEnableable(PluginManifest $manifest): void
    {
        $errors = $this->validateForEnable($manifest);
        if ($errors !== []) {
            throw ValidationException::withMessages(['app' => $errors[0], 'dependencies' => $errors]);
        }
    }

    public function assertRemovable(string $vendor, string $name): void
    {
        $errors = $this->validateForDisableOrUninstall(strtolower($vendor).'/'.strtolower($name));
        if ($errors !== []) {
            throw ValidationException::withMessages(['app' => $errors[0], 'dependencies' => $errors]);
        }
    }

    /**
     * @return array<string, array{version: string, enabled: bool}>
     */
    public function installedMap(): array
    {
        $map = [];
        foreach (InstalledPlugin::query()->get(['vendor', 'name', 'version', 'is_enabled']) as $row) {
            $map[$row->idKey()] = [
                'version' => (string) $row->version,
                'enabled' => (bool) $row->is_enabled,
            ];
        }

        return $map;
    }

    /**
     * Simple constraint matcher: *, exact, >= > <= < =, ^major, ~minor.
     */
    public function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        $version = $this->normalize($version);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (preg_match('/^(>=|<=|>|<|=)\s*(.+)$/', $constraint, $m)) {
            return version_compare($version, $this->normalize($m[2]), $m[1] === '=' ? '==' : $m[1]);
        }

        if (str_starts_with($constraint, '^')) {
            $base = $this->normalize(substr($constraint, 1));
            $parts = array_map('intval', explode('.', $base));
            $upper = ($parts[0] + 1).'.0.0';

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        if (str_starts_with($constraint, '~')) {
            $base = $this->normalize(substr($constraint, 1));
            $parts = array_map('intval', explode('.', $base));
            $upper = $parts[0].'.'.($parts[1] + 1).'.0';

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        return version_compare($version, $this->normalize($constraint), '==');
    }

    /**
     * @param  array<string, array{version: string, enabled: bool}>  $installed
     */
    protected function detectCycle(PluginManifest $candidate, array $installed): ?string
    {
        $graph = [];
        foreach ($installed as $id => $_meta) {
            $m = $this->manifestFor($id);
            $graph[$id] = $m ? array_keys($m->requires) : [];
        }
        $graph[$candidate->id()] = array_keys($candidate->requires);

        $visiting = [];
        $visited = [];

        $dfs = function (string $node) use (&$dfs, &$graph, &$visiting, &$visited): ?string {
            if (isset($visiting[$node])) {
                return $node;
            }
            if (isset($visited[$node])) {
                return null;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $dep) {
                $hit = $dfs($dep);
                if ($hit !== null) {
                    return $node.' → '.$hit;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return null;
        };

        return $dfs($candidate->id());
    }

    protected function manifestFor(string $id): ?PluginManifest
    {
        [$vendor, $name] = array_pad(explode('/', $id, 2), 2, '');
        if ($vendor === '' || $name === '') {
            return null;
        }

        return app(PluginRegistry::class)->find($vendor, $name);
    }

    protected function normalize(string $version): string
    {
        $version = trim($version);
        if (str_contains($version, '-')) {
            $version = explode('-', $version, 2)[0];
        }
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
