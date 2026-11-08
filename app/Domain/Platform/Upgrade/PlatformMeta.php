<?php

namespace App\Domain\Platform\Upgrade;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installed platform / schema versions for merchant upgrades.
 */
final class PlatformMeta
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('wento_platform_meta')) {
            Schema::create('wento_platform_meta', function (Blueprint $table) {
                $table->id();
                $table->string('platform_version', 32)->default('1.0.0');
                $table->string('schema_version', 32)->default('1.0.0');
                $table->timestamp('upgraded_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wento_upgrade_log')) {
            Schema::create('wento_upgrade_log', function (Blueprint $table) {
                $table->id();
                $table->string('version', 32);
                $table->string('status', 32)->default('applied');
                $table->text('message')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
                $table->index(['version', 'status']);
            });
        }

        if (Schema::hasTable('wento_platform_meta') && DB::table('wento_platform_meta')->count() === 0) {
            $version = (string) config('wento.version', '1.0.0');
            DB::table('wento_platform_meta')->insert([
                'platform_version' => $version,
                'schema_version' => $version,
                'upgraded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function stamp(string $version): void
    {
        self::ensure();
        $version = self::normalizeVersion($version);
        $row = DB::table('wento_platform_meta')->orderBy('id')->first();
        if ($row) {
            DB::table('wento_platform_meta')->where('id', $row->id)->update([
                'platform_version' => $version,
                'schema_version' => $version,
                'upgraded_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('wento_platform_meta')->insert([
            'platform_version' => $version,
            'schema_version' => $version,
            'upgraded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function installedVersion(): string
    {
        self::ensure();
        $row = DB::table('wento_platform_meta')->orderBy('id')->first();
        if ($row && trim((string) $row->platform_version) !== '') {
            return self::normalizeVersion((string) $row->platform_version);
        }

        if (config('wento.installed') || is_file((string) config('wento.lock_path'))) {
            self::stamp('1.0.0');

            return '1.0.0';
        }

        return self::normalizeVersion((string) config('wento.version', '1.0.0'));
    }

    public static function codeVersion(): string
    {
        return self::normalizeVersion((string) config('wento.version', '1.0.0'));
    }

    public static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '' || ! preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return '1.0.0';
        }
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m)) {
            return $m[1];
        }

        return '1.0.0';
    }

    public static function log(string $version, string $status, ?string $message = null): void
    {
        self::ensure();
        DB::table('wento_upgrade_log')->insert([
            'version' => self::normalizeVersion($version),
            'status' => $status,
            'message' => $message,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<object>
     */
    public static function recentLogs(int $limit = 20): array
    {
        self::ensure();

        return DB::table('wento_upgrade_log')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}
