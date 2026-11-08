<?php

namespace App\Domain\Plugin;

use Illuminate\Support\Facades\File;

/**
 * Public marketplace catalog of on-disk packages plus optional remote rows.
 * Lists every on-disk package under extensions/ plus optional remote seed rows.
 */
final class ExtensionMarketplace
{
    public function catalogPath(): string
    {
        return resource_path('extension-marketplace/catalog.json');
    }

    /**
     * @return array{version: int, updated_at: ?string, description: ?string, count: int, extensions: list<array<string, mixed>>, local_available: list<array<string, mixed>>}
     */
    public function payload(): array
    {
        $seed = $this->seedCatalog();
        $discovered = $this->discoveredExtensions();
        $byId = [];
        foreach ($seed['extensions'] as $row) {
            $id = strtolower(($row['vendor'] ?? '').'/'.($row['name'] ?? ''));
            if ($id !== '/') {
                $byId[$id] = $row;
            }
        }
        foreach ($discovered as $row) {
            $id = strtolower(($row['vendor'] ?? '').'/'.($row['name'] ?? ''));
            // On-disk packages win over seed stubs.
            $byId[$id] = array_merge($byId[$id] ?? [], $row);
        }

        $extensions = array_values($byId);
        usort($extensions, fn ($a, $b) => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));

        return [
            'version' => $seed['version'],
            'updated_at' => $seed['updated_at'] ?: now()->toDateString(),
            'description' => $seed['description'],
            'count' => count($extensions),
            'extensions' => $extensions,
            'local_available' => app(LocalExtensionCatalog::class)->available(),
        ];
    }

    /**
     * @return array{version: int, updated_at: ?string, description: ?string, extensions: list<array<string, mixed>>}
     */
    protected function seedCatalog(): array
    {
        $seed = [
            'version' => 1,
            'updated_at' => null,
            'description' => null,
            'extensions' => [],
        ];
        $path = $this->catalogPath();
        if (! is_file($path)) {
            return $seed;
        }
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return $seed;
        }
        $seed['version'] = (int) ($decoded['version'] ?? 1);
        $seed['updated_at'] = isset($decoded['updated_at']) ? (string) $decoded['updated_at'] : null;
        $seed['description'] = isset($decoded['description']) ? (string) $decoded['description'] : null;
        $seed['extensions'] = array_values(array_filter(
            $decoded['extensions'] ?? [],
            fn ($row) => is_array($row) && ! empty($row['vendor']) && ! empty($row['name'])
        ));

        return $seed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function discoveredExtensions(): array
    {
        $out = [];
        foreach (app(PluginRegistry::class)->discover() as $manifest) {
            $out[] = [
                'vendor' => $manifest->vendor,
                'name' => $manifest->name,
                'id' => $manifest->id(),
                'display_name' => $manifest->displayName,
                'version' => $manifest->version,
                'author' => $manifest->author,
                'description' => $manifest->description,
                'type' => $manifest->type,
                'category' => $manifest->category,
                'source' => 'local',
                'installable' => true,
            ];
        }

        return $out;
    }
}
