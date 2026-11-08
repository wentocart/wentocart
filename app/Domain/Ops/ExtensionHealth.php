<?php

namespace App\Domain\Ops;

use App\Domain\Plugin\Models\InstalledPlugin;
use Illuminate\Support\Facades\File;
use Throwable;

final class ExtensionHealth
{
    /**
     * @return list<array<string, mixed>>
     */
    public function scan(): array
    {
        try {
            $rows = InstalledPlugin::query()->orderBy('display_name')->get();
        } catch (Throwable) {
            return [];
        }

        return $rows->map(function (InstalledPlugin $plugin) {
            $pathOk = $plugin->path && (File::isDirectory($plugin->path) || File::isFile($plugin->path));
            $status = $plugin->health_status ?: ($pathOk ? 'ok' : 'error');
            if (! $pathOk) {
                $status = 'error';
            }

            return [
                'id' => $plugin->id,
                'key' => $plugin->idKey(),
                'name' => $plugin->display_name ?: $plugin->name,
                'version' => $plugin->version,
                'enabled' => (bool) $plugin->is_enabled,
                'status' => $status,
                'last_error' => $plugin->last_error,
                'capabilities' => $plugin->capabilities ?? [],
                'href' => '/admin/extensions/'.$plugin->vendor.'/'.$plugin->name,
            ];
        })->all();
    }
}
