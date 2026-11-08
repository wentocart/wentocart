<?php

namespace App\Domain\Plugin;

class CompatibilityChecker
{
    /**
     * @return array{ok: bool, message: ?string}
     */
    public function check(PluginManifest $manifest, ?string $platformVersion = null): array
    {
        $platform = $platformVersion ?? (string) config('wento.version', '1.0.0');

        if ($manifest->minAppVersion !== null
            && version_compare($this->normalize($platform), $this->normalize($manifest->minAppVersion), '<')) {
            return [
                'ok' => false,
                'message' => 'Unsupported platform version: needs '.$manifest->minAppVersion.' or newer, running '.$platform.'.',
            ];
        }

        if ($manifest->maxAppVersion !== null
            && version_compare($this->normalize($platform), $this->normalize($manifest->maxAppVersion), '>')) {
            return [
                'ok' => false,
                'message' => 'Unsupported platform version: supports up to '.$manifest->maxAppVersion.', running '.$platform.'.',
            ];
        }

        return ['ok' => true, 'message' => null];
    }

    public function assertCompatible(PluginManifest $manifest, ?string $platformVersion = null): void
    {
        $result = $this->check($manifest, $platformVersion);
        if (! $result['ok']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'zip' => $result['message'] ?? 'Extension is incompatible with this platform version.',
            ]);
        }
    }

    protected function normalize(string $version): string
    {
        $version = trim($version);
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
