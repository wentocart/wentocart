<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Settings\ObjectStorageSettings;
use App\Domain\Settings\PerformanceReport;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Evidence that Laravel / PHP / Octane / Redis / S3 is a production-ready path (not package-only).
 */
class StackRuntimeLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_octane_defaults_to_frankenphp_and_report_exposes_stack(): void
    {
        $this->assertTrue(class_exists(\Laravel\Octane\Octane::class));
        $this->assertFileExists(config_path('octane.php'));
        $this->assertSame('frankenphp', config('octane.server'));
        $this->assertSame('predis', config('database.redis.client'));

        $payload = PerformanceReport::payload();
        $this->assertArrayHasKey('stack', $payload);
        $this->assertArrayHasKey('object_storage', $payload);
        $this->assertArrayHasKey('octane', $payload);

        $this->assertTrue($payload['octane']['installed']);
        $this->assertSame('frankenphp', $payload['stack']['octane_server']);
        $this->assertSame('predis', $payload['stack']['redis_client']);
        $this->assertTrue($payload['object_storage']['package_ready']);
        $this->assertTrue($payload['stack']['object_storage_ready']);
        $this->assertTrue($payload['stack']['recommended']);
    }

    public function test_redis_cache_store_can_be_selected_with_predis_client(): void
    {
        Config::set('cache.default', 'redis');
        Config::set('queue.default', 'redis');
        Config::set('session.driver', 'redis');
        Config::set('database.redis.client', 'predis');

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('queue.default'));
        $this->assertSame('redis', config('session.driver'));
        $this->assertSame('predis', config('database.redis.client'));
        $this->assertArrayHasKey('redis', config('cache.stores'));
        $this->assertArrayHasKey('redis', config('queue.connections'));
    }

    public function test_object_storage_boot_disk_wires_s3_from_env_shaped_settings(): void
    {
        ShopSetting::put('storage.driver', 's3');
        ShopSetting::put('storage.bucket', 'wento-media');
        ShopSetting::put('storage.region', 'us-east-1');
        ShopSetting::put('storage.key', 'test-key');
        ShopSetting::put('storage.secret', 'test-secret');
        ShopSetting::put('storage.endpoint', 'http://minio:9000');
        ShopSetting::put('storage.path_style', true);

        ObjectStorageSettings::bootDisk();

        $disk = config('filesystems.disks.object');
        $this->assertIsArray($disk);
        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('wento-media', $disk['bucket']);
        $this->assertSame('http://minio:9000', $disk['endpoint']);
        $this->assertTrue($disk['use_path_style_endpoint']);
        $this->assertSame('object', config('filesystems.default'));
        $this->assertSame('object', ObjectStorageSettings::diskName());
    }

    public function test_object_disk_round_trip_via_local_stand_in(): void
    {
        $root = storage_path('framework/testing/object-disk');
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        Config::set('filesystems.disks.object', [
            'driver' => 'local',
            'root' => $root,
            'throw' => true,
        ]);

        $key = 'probes/stack-lead.txt';
        Storage::disk('object')->put($key, 'wento-stack-lead');
        $this->assertSame('wento-stack-lead', Storage::disk('object')->get($key));
        Storage::disk('object')->delete($key);
    }

    public function test_performance_admin_exposes_stack_payload(): void
    {
        $this->seed();
        $admin = \App\Models\Admin::query()->first();
        $this->assertNotNull($admin);

        $this->actingAs($admin, 'admin')
            ->get('/admin/performance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Performance')
                ->has('stack.php')
                ->has('stack.redis_client')
                ->has('object_storage.driver')
                ->where('stack.redis_client', 'predis')
            );
    }

    public function test_live_minio_round_trip_when_endpoint_configured(): void
    {
        $endpoint = (string) env('AWS_ENDPOINT', '');
        $bucket = (string) env('AWS_BUCKET', '');
        if ($endpoint === '' || $bucket === '' || ! class_exists(\Aws\S3\S3Client::class)) {
            $this->markTestSkipped('Set AWS_ENDPOINT + AWS_BUCKET (MinIO profile) to run live object round-trip.');
        }

        ShopSetting::put('storage.driver', 's3');
        ShopSetting::put('storage.bucket', $bucket);
        ShopSetting::put('storage.region', (string) env('AWS_DEFAULT_REGION', 'us-east-1'));
        ShopSetting::put('storage.key', (string) env('AWS_ACCESS_KEY_ID', ''));
        ShopSetting::put('storage.secret', (string) env('AWS_SECRET_ACCESS_KEY', ''));
        ShopSetting::put('storage.endpoint', $endpoint);
        ShopSetting::put('storage.path_style', true);

        $this->assertTrue(ObjectStorageSettings::probeRoundTrip('wento-minio-probe'));
    }

    public function test_catalog_product_image_upload_uses_object_disk_when_s3_configured(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        ShopSetting::put('storage.driver', 's3');
        ShopSetting::put('storage.bucket', 'wento-media');
        ShopSetting::put('storage.region', 'us-east-1');
        ShopSetting::put('storage.key', 'test-key');
        ShopSetting::put('storage.secret', 'test-secret');
        ShopSetting::put('storage.endpoint', 'http://minio:9000');
        ShopSetting::put('storage.path_style', true);

        Storage::fake('public');
        Storage::fake('object');

        $this->assertSame('object', ObjectStorageSettings::diskName());
        $this->assertSame('object', PerformanceReport::payload()['stack']['catalog_media_disk']);

        $this->actingAs($admin, 'admin');
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'STACK-S3-IMG-1',
            'price' => 10,
            'is_enabled' => true,
        ]);

        $this->post('/admin/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->image('cover.jpg', 200, 200),
            'cover' => '1',
        ])->assertRedirect();

        $image = ProductImage::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($image);
        Storage::disk('object')->assertExists($image->path);
        Storage::disk('public')->assertMissing($image->path);

        $this->delete('/admin/products/'.$product->id.'/images/'.$image->id)->assertRedirect();
        Storage::disk('object')->assertMissing($image->path);
    }
}
