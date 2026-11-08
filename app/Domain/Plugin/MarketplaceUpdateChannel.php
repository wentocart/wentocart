<?php

namespace App\Domain\Plugin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Optional remote marketplace version checks and zip download for one-click updates.
 */
final class MarketplaceUpdateChannel
{
    public function connected(): bool
    {
        return (bool) config('wento.marketplace.connected')
            && trim((string) config('wento.marketplace.url')) !== '';
    }

    /**
     * @return array<string, string> id => latest version
     */
    public function latestVersions(): array
    {
        // Until wentocart.com marketplace ships, do not invent "updates" from the local catalog.
        if (! $this->connected()) {
            return [];
        }

        $versions = [];
        try {
            $url = rtrim((string) config('wento.marketplace.url'), '/').'/api/v1/extensions/versions';
            $response = Http::timeout(8)->acceptJson()->get($url);
            if (! $response->successful()) {
                return [];
            }
            $data = $response->json();
            $remote = is_array($data['versions'] ?? null) ? $data['versions'] : (is_array($data) ? $data : []);
            foreach ($remote as $id => $ver) {
                if (is_string($id) && is_string($ver) && $ver !== '') {
                    $versions[strtolower($id)] = $ver;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $versions;
    }

    /**
     * Download extension zip from the official marketplace API and return a temp UploadedFile.
     */
    public function downloadZip(string $vendor, string $name): UploadedFile
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);
        if (! $this->connected()) {
            throw ValidationException::withMessages([
                'app' => 'Marketplace is not connected. Set WENTO_MARKETPLACE_CONNECTED and WENTO_MARKETPLACE_URL, or upload a zip.',
            ]);
        }

        $url = rtrim((string) config('wento.marketplace.url'), '/')
            .'/api/v1/extensions/'.rawurlencode($vendor).'/'.rawurlencode($name).'/download';

        try {
            $response = Http::timeout(60)->withOptions(['allow_redirects' => true])->get($url);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'app' => 'Marketplace download failed: '.$e->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'app' => 'Marketplace returned HTTP '.$response->status().' for '.$vendor.'/'.$name.'.',
            ]);
        }

        $body = $response->body();
        if ($body === '' || ! str_starts_with($body, 'PK')) {
            throw ValidationException::withMessages([
                'app' => 'Marketplace response was not a zip file.',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wento-mkt-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file for marketplace zip.');
        }
        $zipPath = $tmp.'.zip';
        @unlink($tmp);
        file_put_contents($zipPath, $body);

        return new UploadedFile($zipPath, $vendor.'-'.$name.'.zip', 'application/zip', null, true);
    }
}
