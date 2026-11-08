<?php

namespace App\Domain\Settings;

/**
 * Object storage (S3 / Cloudflare R2 / MinIO). Credentials stay in shop settings or env;
 * the disk is wired at boot when league/flysystem-aws-s3-v3 is installed.
 */
final class ObjectStorageSettings
{
    public static function driver(): string
    {
        $raw = strtolower(trim((string) ShopSetting::get(
            'storage.driver',
            (string) env('WENTO_STORAGE_DRIVER', 'local')
        )));

        return in_array($raw, ['local', 's3', 'r2'], true) ? $raw : 'local';
    }

    public static function diskName(): string
    {
        return self::driver() === 'local' ? 'public' : 'object';
    }

    /**
     * Active media disk for catalog (and other) uploads.
     * Local shops keep the public disk; S3/R2/MinIO use the runtime "object" disk.
     */
    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        self::bootDisk();

        return \Illuminate\Support\Facades\Storage::disk(self::diskName());
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $driver = self::driver();

        return [
            'driver' => $driver,
            'disk' => self::diskName(),
            'bucket' => (string) ShopSetting::get('storage.bucket', env('AWS_BUCKET', '')),
            'region' => (string) ShopSetting::get('storage.region', env('AWS_DEFAULT_REGION', 'auto')),
            'endpoint' => (string) ShopSetting::get('storage.endpoint', env('AWS_ENDPOINT', '')),
            'key_set' => trim((string) ShopSetting::get('storage.key', env('AWS_ACCESS_KEY_ID', ''))) !== '',
            'secret_set' => trim((string) ShopSetting::get('storage.secret', env('AWS_SECRET_ACCESS_KEY', ''))) !== '',
            'package_ready' => class_exists(\League\Flysystem\AwsS3V3\PortableVisibilityConverter::class)
                || interface_exists(\Aws\S3\S3ClientInterface::class),
            'remote_ready' => $driver !== 'local'
                && class_exists(\Aws\S3\S3Client::class)
                && trim((string) ShopSetting::get('storage.bucket', env('AWS_BUCKET', ''))) !== '',
        ];
    }

    /**
     * Merge a runtime "object" disk into the filesystem config when remote storage is selected.
     */
    public static function bootDisk(): void
    {
        if (self::driver() === 'local') {
            return;
        }
        if (! class_exists(\Aws\S3\S3Client::class)) {
            return;
        }

        $existing = config('filesystems.disks.object');
        // Keep local stand-ins (Feature tests / Storage::fake('object')).
        if (is_array($existing) && ($existing['driver'] ?? '') === 'local') {
            config(['filesystems.default' => 'object']);

            return;
        }

        $endpoint = trim((string) ShopSetting::get('storage.endpoint', env('AWS_ENDPOINT', '')));
        config([
            'filesystems.disks.object' => [
                'driver' => 's3',
                'key' => (string) ShopSetting::get('storage.key', env('AWS_ACCESS_KEY_ID')),
                'secret' => (string) ShopSetting::get('storage.secret', env('AWS_SECRET_ACCESS_KEY')),
                'region' => (string) ShopSetting::get('storage.region', env('AWS_DEFAULT_REGION', 'auto')),
                'bucket' => (string) ShopSetting::get('storage.bucket', env('AWS_BUCKET')),
                'url' => (string) ShopSetting::get('storage.url', env('AWS_URL', '')),
                'endpoint' => $endpoint !== '' ? $endpoint : null,
                'use_path_style_endpoint' => ShopSetting::bool('storage.path_style', (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
                'throw' => true,
            ],
            'filesystems.default' => 'object',
        ]);
    }

    /**
     * Put + get a small probe object on the configured object disk (S3/R2/MinIO).
     * Returns false when remote storage is not configured or the SDK is missing.
     */
    public static function probeRoundTrip(string $contents = 'wento-object-probe'): bool
    {
        self::bootDisk();
        if (self::driver() === 'local' || ! isset(config('filesystems.disks')['object'])) {
            return false;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('object');
        $key = 'wento-probes/stack-'.uniqid('', true).'.txt';
        $disk->put($key, $contents);
        $read = $disk->get($key);
        $disk->delete($key);

        return $read === $contents;
    }
}
