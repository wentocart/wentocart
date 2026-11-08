<?php

namespace Tests\Feature;

use App\Domain\Plugin\MarketplaceUpdateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceUpdateChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_versions_empty_when_not_connected(): void
    {
        config([
            'wento.marketplace.connected' => false,
            'wento.marketplace.url' => '',
        ]);

        $this->assertSame([], app(MarketplaceUpdateChannel::class)->latestVersions());
    }

    public function test_latest_versions_merge_remote_when_connected(): void
    {
        config([
            'wento.marketplace.connected' => true,
            'wento.marketplace.url' => 'https://marketplace.test',
        ]);

        Http::fake([
            'https://marketplace.test/api/v1/extensions/versions' => Http::response([
                'versions' => [
                    'weblytic/wtsstripe' => '9.9.9',
                ],
            ], 200),
        ]);

        $latest = app(MarketplaceUpdateChannel::class)->latestVersions();
        $this->assertSame('9.9.9', $latest['weblytic/wtsstripe'] ?? null);
    }

    public function test_download_zip_requires_connection(): void
    {
        config([
            'wento.marketplace.connected' => false,
            'wento.marketplace.url' => '',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(MarketplaceUpdateChannel::class)->downloadZip('weblytic', 'wtsstripe');
    }

    public function test_download_zip_returns_uploaded_file(): void
    {
        config([
            'wento.marketplace.connected' => true,
            'wento.marketplace.url' => 'https://marketplace.test',
        ]);

        $zipBytes = "PK\x03\x04".str_repeat('x', 32);
        Http::fake([
            'https://marketplace.test/api/v1/extensions/weblytic/wtsstripe/download' => Http::response($zipBytes, 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $file = app(MarketplaceUpdateChannel::class)->downloadZip('weblytic', 'wtsstripe');
        $this->assertTrue($file->isValid() || is_file($file->getRealPath()));
        $this->assertStringEndsWith('.zip', $file->getClientOriginalName());
        @unlink($file->getRealPath());
    }
}
