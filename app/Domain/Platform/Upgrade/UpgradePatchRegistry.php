<?php

namespace App\Domain\Platform\Upgrade;

use Database\Upgrades\V1_0_0_Baseline;
use Illuminate\Support\Facades\File;

final class UpgradePatchRegistry
{
    /**
     * @return list<UpgradePatch>
     */
    public static function all(): array
    {
        $patches = [
            new V1_0_0_Baseline,
        ];

        $dir = database_path('upgrades');
        if (is_dir($dir)) {
            foreach (File::files($dir) as $file) {
                $name = $file->getFilenameWithoutExtension();
                if ($name === 'V1_0_0_Baseline' || ! str_starts_with($name, 'V')) {
                    continue;
                }
                $class = 'Database\\Upgrades\\'.$name;
                if (! class_exists($class)) {
                    require_once $file->getPathname();
                }
                if (class_exists($class) && is_subclass_of($class, UpgradePatch::class)) {
                    $patches[] = new $class;
                }
            }
        }

        usort($patches, fn (UpgradePatch $a, UpgradePatch $b) => version_compare($a->version(), $b->version()));

        $unique = [];
        foreach ($patches as $patch) {
            $unique[$patch->version()] = $patch;
        }

        return array_values($unique);
    }

    /**
     * @return list<UpgradePatch>
     */
    public static function pending(string $fromVersion, string $toVersion): array
    {
        $from = PlatformMeta::normalizeVersion($fromVersion);
        $to = PlatformMeta::normalizeVersion($toVersion);
        $out = [];
        foreach (self::all() as $patch) {
            $v = PlatformMeta::normalizeVersion($patch->version());
            if (version_compare($v, $from, '>') && version_compare($v, $to, '<=')) {
                $out[] = $patch;
            }
        }

        return $out;
    }
}
